<?php

namespace Tests\Feature;

use App\Payment\PatreonManager;
use Illuminate\Support\Facades\Notification;
use PatreonSeeder;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PatreonTest extends TestCase
{
    use refreshDatabase;

    /**
     * Tests the basically functionality for patreon.
     *
     * @return void
     */
    public function testLoadingFromDatabaseWorks()
    {
        $this->seed();
        $this->seed(PatreonSeeder::class);
        $patreonManager = resolve(PatreonManager::class);
        $pledges = $patreonManager->getPatrons();
        $this->assertNotEmpty($pledges);
    }

    public function testLoadingLegacyClaimsFromDatabaseWorks()
    {
        $this->seed();
        $this->seed(PatreonSeeder::class);
        $patreonManager = resolve(PatreonManager::class);
        $claims = $patreonManager->getLegacyclaims();
        $this->assertNotEmpty($claims);
    }

    public function testPreviousContributionsCalculatedCorrectly()
    {
        $this->seed();
        $this->seed(PatreonSeeder::class);
        $patreonManager = resolve(PatreonManager::class);
        $patron = $patreonManager->getPatron(1);
        $previousAmountCents = $patreonManager->getPreviouslyClaimedCents($patron, 1);
        $this->assertTrue($previousAmountCents == 150,
            "Previous claims didn't total correctly. Should have been 150, was {$previousAmountCents}.");
    }

    public function testLegacyClaimsDoNotSendNotification()
    {
        Notification::fake();
        $this->seed();
        $this->seed(PatreonSeeder::class);
        $this->artisan('patreon:convertlegacy')
            ->assertExitCode(0);
        Notification::assertNothingSent();
    }

    public function testLegacyClaimsTotalCorrectly()
    {
        $this->seed();
        $this->seed(PatreonSeeder::class);
        $this->artisan('patreon:convertlegacy')
            ->assertExitCode(0);
        $patreonManager = resolve(PatreonManager::class);
        $patron = $patreonManager->getPatron(1);
        $previousAmountCents = $patreonManager->getPreviouslyClaimedCents($patron, 1);
        $this->assertEquals($previousAmountCents,250);
    }
}
