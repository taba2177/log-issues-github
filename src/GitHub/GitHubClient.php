<?php

declare(strict_types=1);

namespace Taba\LogIssues\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Taba\LogIssues\Pipeline\OccurrenceStore;
use Taba\LogIssues\Settings;
use Throwable;

/**
 * Thin GitHub REST client: short timeouts, retries with backoff on 5xx / connection errors,
 * rate-limit aware, feeds the circuit breaker. Every failure surfaces as GitHubException.
 */
final class GitHubClient
{
    public function __construct(
        private readonly Http $http,
        private readonly Settings $settings,
        private readonly OccurrenceStore $store,
    ) {}

    /** @return array<string, mixed> */
    public function repository(): array
    {
        return $this->json($this->send('read repository', 'get', $this->repoPath()));
    }

    /** @return array{number: int, state: string, html_url: string}|null */
    public function findIssueByMarker(string $fingerprint): ?array
    {
        $marker = 'log-issues:fp='.$fingerprint;
        $response = $this->send('search issues', 'get', '/search/issues', [
            'q' => sprintf('repo:%s is:issue in:body "%s"', $this->settings->repo(), $marker),
            'per_page' => 10,
        ]);

        $items = $this->json($response)['items'] ?? [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item['body'] ?? null) && str_contains($item['body'], $marker)) {
                return self::toIssue($item);
            }
        }

        return null;
    }

    /** @return array{number: int, state: string, html_url: string}|null  Null when deleted / transferred. */
    public function issue(int $number): ?array
    {
        $response = $this->send('read issue', 'get', $this->repoPath("/issues/{$number}"), [], [404, 410]);

        if (in_array($response->status(), [404, 410], true)) {
            return null;
        }

        return self::toIssue($this->json($response));
    }

    /**
     * @param  list<string>  $labels
     * @return array{number: int, state: string, html_url: string}
     */
    public function createIssue(string $title, string $body, array $labels): array
    {
        $response = $this->send('create issue', 'post', $this->repoPath('/issues'), [
            'title' => $title,
            'body' => $body,
            'labels' => array_values(array_unique($labels)),
        ]);

        return self::toIssue($this->json($response));
    }

    public function comment(int $number, string $body): void
    {
        $this->send('comment', 'post', $this->repoPath("/issues/{$number}/comments"), ['body' => $body]);
    }

    public function reopen(int $number): void
    {
        $this->send('reopen issue', 'patch', $this->repoPath("/issues/{$number}"), ['state' => 'open']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $acceptedStatuses  Non-2xx statuses that are an answer, not a failure.
     */
    private function send(string $operation, string $method, string $path, array $payload = [], array $acceptedStatuses = []): Response
    {
        if ($this->store->circuitOpen()) {
            throw new GitHubException("GitHub {$operation} skipped: circuit breaker open");
        }

        try {
            $response = $this->request()->{$method}($path, $payload);
        } catch (ConnectionException $e) {
            $this->store->recordFailure("{$operation}: connection failed (".$e->getMessage().')');
            throw new GitHubException("GitHub {$operation} failed: ".$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $response = $e->response;
        } catch (Throwable $e) {
            $this->store->recordFailure("{$operation}: ".$e->getMessage());
            throw new GitHubException("GitHub {$operation} failed: ".$e->getMessage(), 0, $e);
        }

        /** @var Response $response */
        if ($response->successful() || in_array($response->status(), $acceptedStatuses, true)) {
            $this->store->recordSuccess();

            return $response;
        }

        $this->honourRateLimit($response);
        $message = $this->errorMessage($response);
        $exception = GitHubException::fromStatus($operation, $response->status(), $message);
        $this->store->recordFailure($exception->getMessage());

        throw $exception;
    }

    private function request(): PendingRequest
    {
        $timeout = $this->settings->timeoutSeconds();

        $request = $this->http
            ->baseUrl($this->settings->apiUrl())
            ->withToken($this->settings->token())
            ->acceptJson()
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'taba2177-log-issues-github',
            ])
            ->timeout($timeout)
            ->connectTimeout($timeout);

        $retries = $this->settings->retries();
        if ($retries > 0) {
            $request->retry(
                $retries + 1,
                static fn (int $attempt): int => 250 * $attempt,
                static fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                false,
            );
        }

        return $request;
    }

    /** 403/429 with exhausted rate limit: pause all calls until GitHub says we may retry. */
    private function honourRateLimit(Response $response): void
    {
        if (! in_array($response->status(), [403, 429], true)) {
            return;
        }

        $retryAfter = $response->header('Retry-After');
        $remaining = $response->header('X-RateLimit-Remaining');
        $reset = $response->header('X-RateLimit-Reset');

        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            $this->store->openCircuit(Carbon::now()->addSeconds(max(1, (int) $retryAfter)));
        } elseif ($remaining === '0' && is_numeric($reset)) {
            $until = Carbon::createFromTimestamp((int) $reset);
            $this->store->openCircuit($until->isFuture() ? $until : Carbon::now()->addMinute());
        } elseif ($response->status() === 429) {
            $this->store->openCircuit(Carbon::now()->addMinutes(1));
        }
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();
        $message = is_array($json) && is_string($json['message'] ?? null) ? $json['message'] : '';

        return mb_substr($message, 0, 200);
    }

    private function repoPath(string $suffix = ''): string
    {
        return '/repos/'.$this->settings->repo().$suffix;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<mixed>  $data
     * @return array{number: int, state: string, html_url: string}
     */
    private static function toIssue(array $data): array
    {
        $number = $data['number'] ?? null;
        if (! is_int($number)) {
            throw new GitHubException('GitHub returned an issue without a number');
        }

        return [
            'number' => $number,
            'state' => is_string($data['state'] ?? null) ? $data['state'] : 'open',
            'html_url' => is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
        ];
    }
}
