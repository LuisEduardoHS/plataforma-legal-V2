<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestingIsolation;

final class TestingDatabaseTargetTest extends TestCase
{
    public function test_target_is_configurable_without_a_codex_bootstrap(): void
    {
        $previous = getenv('EXPECTED_TEST_DB');
        try {
            foreach (['plataforma_legal_v2_codex_test', 'plataforma_legal_v2_antigravity_test', 'ci_media_test'] as $target) {
                putenv('EXPECTED_TEST_DB='.$target);
                $this->assertSame($target, TestingIsolation::database());
            }
            putenv('EXPECTED_TEST_DB=plataforma_legal_v2');
            $this->expectException(\RuntimeException::class);
            TestingIsolation::database();
        } finally {
            putenv($previous === false ? 'EXPECTED_TEST_DB' : 'EXPECTED_TEST_DB='.$previous);
        }
    }
}
