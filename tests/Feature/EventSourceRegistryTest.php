<?php

use App\Contracts\EventSource;
use App\Contracts\OutputAdapter;
use App\Contracts\ThreadKeyDeriver;
use App\EventSources\GitHub\GitHubEventSource;
use App\EventSources\GitHub\GitHubOutputAdapter;
use App\EventSources\GitHub\GitHubThreadKeyDeriver;
use App\Services\EventSourceRegistry;
use Illuminate\Http\Request;

it('registers and retrieves event sources', function () {
    $registry = new EventSourceRegistry;

    $source = Mockery::mock(EventSource::class);
    $output = Mockery::mock(OutputAdapter::class);
    $threadKey = Mockery::mock(ThreadKeyDeriver::class);

    $registry->register('test', $source, $output, $threadKey);

    expect($registry->source('test'))->toBe($source)
        ->and($registry->output('test'))->toBe($output)
        ->and($registry->threadKey('test'))->toBe($threadKey);
});

it('lists all registered source names', function () {
    $registry = new EventSourceRegistry;

    $source = Mockery::mock(EventSource::class);
    $output = Mockery::mock(OutputAdapter::class);
    $threadKey = Mockery::mock(ThreadKeyDeriver::class);

    $registry->register('github', $source, $output, $threadKey);
    $registry->register('gitlab', $source, $output, $threadKey);

    expect($registry->sources())->toBe(['github', 'gitlab']);
});

it('detects the correct source from a request', function () {
    $registry = new EventSourceRegistry;

    $githubSource = Mockery::mock(EventSource::class);
    $githubSource->shouldReceive('validates')->andReturnUsing(function (Request $request) {
        return $request->hasHeader('X-GitHub-Event');
    });

    $gitlabSource = Mockery::mock(EventSource::class);
    $gitlabSource->shouldReceive('validates')->andReturnUsing(function (Request $request) {
        return $request->hasHeader('X-Gitlab-Event');
    });

    $output = Mockery::mock(OutputAdapter::class);
    $threadKey = Mockery::mock(ThreadKeyDeriver::class);

    $registry->register('github', $githubSource, $output, $threadKey);
    $registry->register('gitlab', $gitlabSource, $output, $threadKey);

    $githubRequest = Request::create('/webhook', 'POST');
    $githubRequest->headers->set('X-GitHub-Event', 'issues');

    $gitlabRequest = Request::create('/webhook', 'POST');
    $gitlabRequest->headers->set('X-Gitlab-Event', 'Issue Hook');

    $unknownRequest = Request::create('/webhook', 'POST');

    expect($registry->detect($githubRequest))->toBe('github')
        ->and($registry->detect($gitlabRequest))->toBe('gitlab')
        ->and($registry->detect($unknownRequest))->toBeNull();
});

it('throws on unknown source name', function () {
    $registry = new EventSourceRegistry;

    $registry->source('nonexistent');
})->throws(InvalidArgumentException::class, 'Unknown event source: nonexistent');

it('throws on unknown output adapter', function () {
    $registry = new EventSourceRegistry;

    $registry->output('nonexistent');
})->throws(InvalidArgumentException::class, 'Unknown event source: nonexistent');

it('throws on unknown thread key deriver', function () {
    $registry = new EventSourceRegistry;

    $registry->threadKey('nonexistent');
})->throws(InvalidArgumentException::class, 'Unknown event source: nonexistent');

it('resolves from the container with registered sources', function () {
    $registry = app(EventSourceRegistry::class);

    expect($registry->sources())->toContain('github')
        ->and($registry->source('github'))->toBeInstanceOf(GitHubEventSource::class)
        ->and($registry->output('github'))->toBeInstanceOf(GitHubOutputAdapter::class)
        ->and($registry->threadKey('github'))->toBeInstanceOf(GitHubThreadKeyDeriver::class);
});
