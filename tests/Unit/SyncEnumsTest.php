<?php

namespace Tests\Unit;

use App\Enums\SyncStatus;
use App\Enums\TargetType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SyncEnumsTest extends TestCase
{
    /** @return array<string, array{SyncStatus, bool, bool, bool}> */
    public static function statuses(): array
    {
        return [
            'idle' => [SyncStatus::Idle, false, true, false],
            'queued' => [SyncStatus::Queued, true, false, true],
            'syncing' => [SyncStatus::Syncing, true, false, false],
            'synced' => [SyncStatus::Synced, false, true, false],
            'failed' => [SyncStatus::Failed, false, true, false],
            'rate limited' => [SyncStatus::RateLimited, true, false, true],
        ];
    }

    #[DataProvider('statuses')]
    public function test_status_controls_match_the_sync_lifecycle(SyncStatus $status, bool $inProgress, bool $canStart, bool $canCancel): void
    {
        $this->assertSame($inProgress, $status->isInProgress());
        $this->assertSame($canStart, $status->canStartSync());
        $this->assertSame($canCancel, $status->canCancel());
    }

    public function test_github_types_are_case_insensitive_and_unknown_types_stay_unknown(): void
    {
        $this->assertSame(TargetType::User, TargetType::fromGitHub('uSeR'));
        $this->assertSame(TargetType::Organization, TargetType::fromGitHub('ORGANIZATION'));
        $this->assertNull(TargetType::fromGitHub('Bot'));
        $this->assertNull(TargetType::fromGitHub(''));
    }
}
