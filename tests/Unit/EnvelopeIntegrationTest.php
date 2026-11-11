<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit;

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Tests\Integration\LiveApiTest;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;

final class EnvelopeIntegrationTest extends TestCase
{
    private LiveApiTest $live;
    private string|false $previousRequiredFeatures;

    protected function setUp(): void
    {
        $this->live = new LiveApiTest('testCompletedEnvelopeArtifacts');
        $this->previousRequiredFeatures = getenv('ASSINAFY_REQUIRE_PLAN_FEATURES');
    }

    protected function tearDown(): void
    {
        putenv($this->previousRequiredFeatures === false
            ? 'ASSINAFY_REQUIRE_PLAN_FEATURES'
            : 'ASSINAFY_REQUIRE_PLAN_FEATURES=' . $this->previousRequiredFeatures);
        (new \ReflectionMethod($this->live, 'tearDown'))->invoke($this->live);
    }

    public static function featureErrors(): iterable
    {
        yield 'optional plan restriction' => [403, 'PlanRestricted', false, true];
        yield 'required plan restriction' => [403, 'PlanRestricted', true, false];
        yield 'missing scope' => [403, 'InsufficientScope', false, false];
        yield 'inactive membership' => [403, 'InactiveMembership', false, false];
        yield 'unknown denial' => [403, null, false, false];
        yield 'invalid credentials' => [401, null, false, false];
        yield 'server failure' => [500, 'PlanRestricted', false, false];
    }

    #[DataProvider('featureErrors')]
    public function testFeatureCallSkipsOnlyOptionalPlanRestrictions(
        int $status,
        ?string $code,
        bool $required,
        bool $shouldSkip
    ): void {
        putenv('ASSINAFY_REQUIRE_PLAN_FEATURES=' . ($required ? '1' : '0'));
        $error = new ApiException('Request refused', $status, ['code' => $code]);
        $operation = static function () use ($error): array {
            throw $error;
        };
        try {
            (new \ReflectionMethod($this->live, 'withEnvelopeFeature'))->invoke($this->live, $operation);
            $this->fail('Expected the request failure to propagate');
        } catch (SkippedTest) {
            $this->assertTrue($shouldSkip, 'Permission failures must not be skipped');
        } catch (ApiException $caught) {
            $this->assertFalse($shouldSkip);
            $this->assertSame($error, $caught);
        }
    }

    public function testFeatureCallPreservesSuccess(): void
    {
        $result = (new \ReflectionMethod($this->live, 'withEnvelopeFeature'))->invoke(
            $this->live,
            static fn (): array => ['id' => 'envelope']
        );
        $this->assertSame(['id' => 'envelope'], $result);
    }

    public function testEstimatePermissionFailurePropagatesThroughLiveScenario(): void
    {
        putenv('ASSINAFY_REQUIRE_PLAN_FEATURES=0');
        $http = $this->prepareLiveClient(2, false);
        $error = new ApiException('Missing scope', 403, ['code' => 'InsufficientScope']);
        $http->queueException($error);
        for ($i = 0; $i < 3; $i++) {
            $http->queueJson(200, []);
        }
        $this->expectExceptionObject($error);
        $this->live->testEnvelopeVerificationEstimates();
    }

    public static function readFailures(): iterable
    {
        yield 'envelope list' => [false];
        yield 'document read' => [true];
    }

    #[DataProvider('readFailures')]
    public function testLifecycleReadFailuresAreNeverFeatureSkips(bool $documentRead): void
    {
        putenv('ASSINAFY_REQUIRE_PLAN_FEATURES=0');
        $http = $this->prepareLiveClient(3, true);
        $http->queueJson(200, [['id' => 'field', 'type' => 'signature']]);
        $http->queueJson(200, ['documents' => 1, 'total_credits' => 0, 'has_sufficient_resources' => true]);
        if ($documentRead) {
            $http->queueJson(200, []);
            $http->queueException(new ApiException('Invalid payload', 400));
            $http->queueException(new ApiException('Invalid payload', 400));
            $http->queueJson(200, []);
        }
        $error = new ApiException('Read refused', 403, ['code' => 'PlanRestricted']);
        $http->queueException($error);
        for ($i = 0; $i < 4; $i++) {
            $http->queueJson(200, []);
        }
        $this->expectExceptionObject($error);
        $this->live->testEnvelopeLifecycle();
    }

    private function prepareLiveClient(int $documentCount, bool $lookupSigner): FakeHttpClient
    {
        $http = new FakeHttpClient();
        if ($lookupSigner) {
            $http->queueJson(200, []);
        }
        $http->queueJson(201, ['id' => 'signer']);
        for ($i = 0; $i < $documentCount; $i++) {
            $http->queueJson(201, ['id' => 'document-' . $i]);
            $http->queueJson(200, ['status' => 'metadata_ready', 'pages' => [['id' => 'page']]]);
        }
        (new \ReflectionProperty($this->live, 'client'))->setValue(
            $this->live,
            new AssinafyClient(new Configuration('dummy-key', 'dummy-account'), $http)
        );
        return $http;
    }

    public static function archives(): iterable
    {
        foreach ([false, true] as $bundle) {
            foreach (['valid', 'same-content', 'duplicate', 'missing', 'unexpected', 'wrong-content', 'extra-file'] as $case) {
                yield ($bundle ? 'bundle ' : 'single ') . $case => [$bundle, $case];
            }
        }
        yield 'bundle split position across folders' => [true, 'split-folder'];
        yield 'bundle unexpected nested folder' => [true, 'nested-folder'];
        yield 'bundle missing artifact' => [true, 'missing-artifact'];
        yield 'bundle duplicate artifact' => [true, 'duplicate-artifact'];
    }

    #[DataProvider('archives')]
    public function testArchiveRequiresEveryDocumentAndArtifactExactlyOnce(bool $bundle, string $case): void
    {
        $expected = [];
        $files = [];
        foreach ([1, 2] as $position) {
            foreach ($bundle ? ['original', 'certificated', 'certificate-page'] : ['original'] as $artifact) {
                $content = "%PDF-1.4 document $position $artifact\n%%EOF";
                $expected[$position][] = hash('sha256', $content);
                $path = $position . ' - document' . ($bundle ? '/' . $artifact : '') . '.pdf';
                $files[$path] = $content;
            }
        }
        $first = array_key_first($files);
        if ($case === 'duplicate' || $case === 'missing') {
            $files = array_filter($files, static fn (string $path): bool => str_starts_with($path, '1 - '), ARRAY_FILTER_USE_KEY);
            if ($case === 'duplicate') {
                foreach ($files as $path => $content) {
                    $files[str_replace('1 - document', '1 - duplicate', $path)] = $content;
                }
            }
        } elseif ($case === 'same-content') {
            $content = '%PDF-1.4 identical content';
            $files = array_fill_keys(array_keys($files), $content);
            foreach ($expected as &$hashes) {
                $hashes = array_fill(0, count($hashes), hash('sha256', $content));
            }
            unset($hashes);
        } elseif ($case === 'unexpected') {
            $files['3 - extra' . ($bundle ? '/original' : '') . '.pdf'] = '%PDF-1.4 unexpected';
        } elseif ($case === 'wrong-content') {
            $files[$first] = '%PDF-1.4 wrong document';
        } elseif ($case === 'extra-file') {
            $files['notes.txt'] = 'Unexpected content';
        } elseif ($case === 'split-folder') {
            $files['1 - other/original.pdf'] = $files[$first];
            unset($files[$first]);
        } elseif ($case === 'missing-artifact') {
            unset($files[$first]);
        } elseif ($case === 'nested-folder') {
            $files['1 - document/unexpected-subfolder/original.pdf'] = $files[$first];
            unset($files[$first]);
        } elseif ($case === 'duplicate-artifact') {
            $files['1 - document/duplicate.pdf'] = $files[$first];
        }
        $base = tempnam(sys_get_temp_dir(), 'envelope-regression-');
        $this->assertIsString($base);
        unlink($base);
        $path = $base . '.zip';
        try {
            $archive = new \PharData($path);
            foreach ($files as $name => $content) {
                $archive[$name] = $content;
            }
            unset($archive);
            $bytes = file_get_contents($path);
            $this->assertIsString($bytes);
            $failure = null;
            try {
                (new \ReflectionMethod($this->live, 'assertEnvelopeArchive'))->invoke(
                    $this->live,
                    $bytes,
                    $expected,
                    $bundle
                );
            } catch (AssertionFailedError $error) {
                $failure = $error;
            }
            if (in_array($case, ['valid', 'same-content'], true)) {
                $this->assertNull($failure, $failure?->getMessage() ?? '');
            } else {
                $this->assertNotNull($failure, 'Malformed archive must be rejected: ' . $case);
            }
        } finally {
            unlink($path);
        }
    }
}
