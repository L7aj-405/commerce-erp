<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Support\BusinessTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Morocco's legal time, as the RUNTIME implements it (F-02).
 *
 * Legal history this test encodes (Decree 2.26.530 of 25 June 2026, repealing Decree 2.18.855 of 2018):
 *  - UTC+1 all year, except UTC+0 during Ramadan (2018-2026 regime) — e.g. 2026-02-15 02:00Z → 2026-03-22 02:00Z;
 *  - on 2026-09-20 at 02:00 local (01:00Z) clocks went back 60 minutes: UTC+0 (GMT) permanently since;
 *  - no Ramadan adjustment in 2027 or later.
 *
 * An IANA identifier alone does not guarantee this: PHP's bundled/installed timezone database must be
 * 2026c or newer (the change shipped on 2026-07-08). A runtime on an older database still predicts UTC+1
 * after 2026-09-20 and a Ramadan transition in February 2027, silently shifting every Moroccan business date
 * by an hour — see testRuntimeTimezoneDatabaseImplementsTheSeptember2026LegalChange, which FAILS when the
 * runtime is outdated (install the PECL `timezonedb` extension, as the CI image does, or upgrade PHP).
 *
 * Every instant below is an explicit UTC instant. Nothing depends on the system clock.
 */
class MoroccoLegalTimeTest extends TestCase
{
    private const MA = 'Africa/Casablanca';

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // ---- Facts that do NOT depend on the 2026 change (must hold on any runtime) -----------------------

    public function test_historical_utc_plus_one_is_never_rewritten_to_utc(): void
    {
        $this->assertOffsetAndLocal('2026-07-15 12:00', 3600, '2026-07-15 13:00', 'Summer 2026: UTC+1 (legal until 2026-09-20).');
        $this->assertOffsetAndLocal('2025-12-31 23:30', 3600, '2026-01-01 00:30', 'The instant used by the fiscal-boundary regression tests.');
    }

    public function test_ramadan_2026_was_utc_plus_zero(): void
    {
        $this->assertOffsetAndLocal('2026-02-20 12:00', 0, '2026-02-20 12:00', 'Ramadan 2026.');
    }

    public function test_the_march_22_2026_transition_back_to_utc_plus_one(): void
    {
        $this->assertOffsetAndLocal('2026-03-21 12:00', 0, '2026-03-21 12:00', 'Day before: still Ramadan hours (UTC+0).');
        $this->assertOffsetAndLocal('2026-03-22 12:00', 3600, '2026-03-22 13:00', 'Day of: UTC+1 again.');
    }

    public function test_business_dates_in_the_utc_plus_one_period_differ_from_the_utc_date_at_the_boundary(): void
    {
        $this->travelTo(Carbon::parse('2025-12-31 23:30:00', 'UTC'));

        $this->assertSame('2026-01-01', BusinessTime::today($this->organization(self::MA)));
        $this->assertSame(2026, BusinessTime::currentYear($this->organization(self::MA)));
        $this->assertSame('2025-12-31', BusinessTime::today($this->organization(null)), 'An unconfigured organization stays on UTC.');
        $this->assertSame(2025, BusinessTime::currentYear($this->organization(null)));
    }

    public function test_organizations_outside_morocco_keep_their_own_timezone(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'UTC'));

        $this->assertSame('2026-12-31', BusinessTime::today($this->organization('America/New_York')), 'New York is still on 31 December (19:30).');
        $this->assertSame(2026, BusinessTime::currentYear($this->organization('America/New_York')));
        $this->assertSame('2027-01-01', BusinessTime::today($this->organization('Europe/Paris')), 'Paris: 01:30 on 1 January.');
        $this->assertSame('2027-01-01', BusinessTime::today($this->organization('UTC')));
    }

    // ---- Facts that REQUIRE the 2026c+ timezone database ----------------------------------------------

    public function test_runtime_timezone_database_implements_the_september_2026_legal_change(): void
    {
        $offset = $this->offsetAt('2026-11-17 12:00');
        $future = (new DateTimeZone(self::MA))->getTransitions(strtotime('2027-01-01 UTC'), strtotime('2028-01-01 UTC'));

        $this->assertSame(0, $offset, sprintf(
            'Africa/Casablanca is UTC+%d on 2026-11-17 but Morocco returned to GMT on 2026-09-20 (Decree 2.26.530). '
            .'The runtime timezone database is OUTDATED (timezone_version_get() = %s; needs 2026c or newer, released 2026-07-08). '
            .'Every Moroccan business date after 2026-09-20 would be shifted by one hour. Install/upgrade the PECL `timezonedb` extension or PHP.',
            $offset / 3600, timezone_version_get(),
        ));
        $this->assertCount(1, $future, 'No Ramadan (or any) clock change exists in 2027: only the initial state may be listed.');
    }

    public function test_the_september_20_2026_transition_to_gmt(): void
    {
        $this->requireCurrentTimezoneDatabase();

        $this->assertOffsetAndLocal('2026-09-19 12:00', 3600, '2026-09-19 13:00', 'Day before: UTC+1.');
        $this->assertOffsetAndLocal('2026-09-20 12:00', 0, '2026-09-20 12:00', 'Day of, after 02:00: GMT.');
    }

    public function test_the_repeated_local_hour_of_the_september_rollback(): void
    {
        $this->requireCurrentTimezoneDatabase();

        // At 02:00 local (01:00Z) the clocks went back to 01:00: the local hour 01:00-02:00 happened twice.
        $first = $this->local('2026-09-20 00:30');
        $second = $this->local('2026-09-20 01:30');
        $this->assertSame('2026-09-20 01:30', $first->format('Y-m-d H:i'));
        $this->assertSame('2026-09-20 01:30', $second->format('Y-m-d H:i'), 'Same wall-clock time, one hour apart.');
        $this->assertSame([3600, 0], [$first->getOffset(), $second->getOffset()]);

        $this->travelTo(Carbon::parse('2026-09-20 01:30:00', 'UTC'));
        $this->assertSame('2026-09-20', BusinessTime::today($this->organization(self::MA)), 'Both occurrences share one business date.');
        $this->travelTo(Carbon::parse('2026-09-20 00:30:00', 'UTC'));
        $this->assertSame('2026-09-20', BusinessTime::today($this->organization(self::MA)));
    }

    public function test_the_ambiguous_local_hour_resolves_to_one_of_its_two_valid_utc_instants(): void
    {
        $this->requireCurrentTimezoneDatabase();

        $utc = BusinessTime::localToUtc($this->organization(self::MA), '2026-09-20', '01:30')->format('Y-m-d H:i');

        $this->assertContains($utc, ['2026-09-20 00:30', '2026-09-20 01:30'], 'A repeated local time must map to one of its two real instants, never to a third.');
    }

    public function test_november_and_december_2026_are_gmt_and_the_year_ends_in_utc(): void
    {
        $this->requireCurrentTimezoneDatabase();

        $this->assertOffsetAndLocal('2026-11-17 12:00', 0, '2026-11-17 12:00', 'November 2026.');
        $this->assertOffsetAndLocal('2026-12-31 23:30', 0, '2026-12-31 23:30', 'Still 31 December in Casablanca.');
        $this->travelTo(Carbon::parse('2026-12-31 23:30:00', 'UTC'));
        $this->assertSame('2026-12-31', BusinessTime::today($this->organization(self::MA)));
        $this->assertSame(2026, BusinessTime::currentYear($this->organization(self::MA)));
    }

    public function test_january_1_2027_00_30_is_the_same_date_in_utc_and_in_casablanca(): void
    {
        $this->requireCurrentTimezoneDatabase();

        $this->assertOffsetAndLocal('2027-01-01 00:30', 0, '2027-01-01 00:30', 'The old assumption "UTC is still 31 December" is wrong under GMT.');
        $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame('2027-01-01', BusinessTime::today($this->organization(self::MA)));
        $this->assertSame(2027, BusinessTime::currentYear($this->organization(self::MA)));
    }

    public function test_ramadan_2027_and_the_following_months_have_no_clock_change(): void
    {
        $this->requireCurrentTimezoneDatabase();

        $this->assertOffsetAndLocal('2027-02-10 12:00', 0, '2027-02-10 12:00', 'Ramadan 2027: no obsolete transition.');
        $this->assertOffsetAndLocal('2027-03-15 12:00', 0, '2027-03-15 12:00', 'After Ramadan 2027: still GMT (the old rule predicted UTC+1).');
        $this->assertOffsetAndLocal('2027-07-15 12:00', 0, '2027-07-15 12:00', 'Summer 2027: GMT.');
    }

    // ---------------------------------------------------------------------------------------------------

    private function assertOffsetAndLocal(string $utc, int $offset, string $local, string $why): void
    {
        $value = $this->local($utc);
        $this->assertSame([$offset, $local], [$value->getOffset(), $value->format('Y-m-d H:i')], $why.' ('.$utc.'Z)');
    }

    private function local(string $utc): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc.':00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::MA));
    }

    private function offsetAt(string $utc): int
    {
        return $this->local($utc)->getOffset();
    }

    private function organization(?string $timezone): Organization
    {
        $organization = new Organization;
        $organization->settings = $timezone === null ? [] : ['timezone' => $timezone];

        return $organization;
    }

    /** Skips (the dedicated guard test fails loudly) when the runtime does not know the 2026-09-20 change. */
    private function requireCurrentTimezoneDatabase(): void
    {
        if ($this->offsetAt('2026-11-17 12:00') !== 0) {
            $this->markTestSkipped('Runtime timezone database '.timezone_version_get().' predates 2026c: see the guard test.');
        }
    }
}
