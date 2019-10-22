<?php

namespace App\Http\Controllers;

use App\Contracts\MuckConnection;
use App\Muck\MuckCharacter;

class CharacterController extends Controller
{
    public function show(MuckConnection $muck, string $characterName)
    {
        return view('character')->with([
            'character' => $characterName
        ]);
    }
}
