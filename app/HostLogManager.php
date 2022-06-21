<?php


namespace App;

use App\User as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class HostLogManager
{

    public function logHost(string $ip, ?User $user): void
    {
        if (!$user) return;
        if ($ip === '127.0.0.1') return;

        // Have to check the table exists because it might not during testing
        if (Schema::hasTable('log_hosts')) {
            $character = $user->getCharacter();
            $hostname = gethostbyaddr($ip);
            DB::table('log_hosts')->updateOrInsert(
                [
                    'host_ip' => $ip,
                    'aid' => $user->getAid(), // To match existing format
                    'plyr_ref' => $character ? $character->dbref() : -1, // To match existing format
                    'muckname' => config('muck.muck_name')
                ], [
                    'host_name' => $hostname,
                    'plyr_name' => $character ? $character->name() : '', // To match existing format
                    'tstamp' => Carbon::now()->timestamp
                ]
            );
        }
    }
}
