<?php

namespace App\SupportTickets;

use App\Muck\MuckDbref;
use App\User;
use Illuminate\Support\Carbon;

class SupportTicket
{
    public int $id;
    public string $categoryCode;
    public string $title;
    public ?int $gameCode;
    public ?User $fromUser;
    public ?MuckDbref $fromCharacter;
    public ?User $agentUser;
    public ?MuckDbref $agentCharacter;
    public Carbon $createdAt;
    public Carbon $updatedAt;
    public string $status;
    public Carbon $statusAt;
    public ?string $closureReason;
    public ?Carbon $closedAt;
    public bool $isPublic;
    public string $content;
    public int $votesUp;
    public int $votesDown;

    /**
     * Use the createNew and createExisting static functions instead
     * @param int $id
     * @param string $categoryCode
     * @param string $title
     * @param string $content
     * @param int $gameCode
     * @param User|null $user
     * @param MuckDbref|null $character
     */
    private function __construct(
        int        $id,
        string     $categoryCode,
        string     $title,
        string     $content,
        ?int       $gameCode,
        ?User      $user,
        ?MuckDbref $character
    )
    {
        $this->id = $id;
        $this->categoryCode = $categoryCode;
        $this->title = $title;
        $this->content = $content;
        $this->gameCode = $gameCode;
        $this->fromUser = $user;
        $this->fromCharacter = $character;
    }

    public static function createNew(
        int        $id,
        string     $categoryCode,
        string     $title,
        string     $content,
        ?User      $fromUser,
        ?MuckDbref $fromCharacter
    ): SupportTicket
    {
        $ticket = new self(
            $id,
            $categoryCode,
            $title,
            $content,
            config('muck.muck_code'),
            $fromUser,
            $fromCharacter
        );

        //Defaults for new ticket
        $ticket->createdAt = Carbon::now();
        $ticket->status = 'new';
        $ticket->statusAt = Carbon::now();
        $ticket->closedAt = null;
        $ticket->closureReason = null;
        $ticket->isPublic = false;
        $ticket->updatedAt = Carbon::now();
        return $ticket;
    }

    public static function createExisting(
        int        $id,
        string     $categoryCode,
        string     $title,
        ?int       $gameCode,
        ?User      $fromUser,
        ?MuckDbref $fromCharacter,
        ?User      $agentUser,
        ?MuckDbref $agentCharacter,
        Carbon     $createdAt,
        string     $status,
        Carbon     $statusAt,
        Carbon     $updatedAt,
        ?string    $closureReason,
        ?Carbon    $closedAt,
        bool       $isPublic,
        int        $votesUp,
        int        $votesDown,
        string     $content
    ): SupportTicket
    {
        $ticket = new self(
            $id,
            $categoryCode,
            $title,
            $content,
            $gameCode,
            $fromUser,
            $fromCharacter
        );
        $ticket->agentUser = $agentUser;
        $ticket->agentCharacter = $agentCharacter;
        $ticket->createdAt = $createdAt;
        $ticket->status = $status;
        $ticket->statusAt = $statusAt;
        $ticket->updatedAt = $updatedAt;
        $ticket->closedAt = $closedAt;
        $ticket->closureReason = $closureReason;
        $ticket->isPublic = $isPublic;
        $ticket->votesUp = $votesUp;
        $ticket->votesDown = $votesDown;
        return $ticket;
    }

    public function __toString(): string
    {
        return "SupportTicket#$this->id[$this->categoryCode/$this->title]";
    }

    // Shared parts of listing serialization
    private function serializeForListing(User $user) : array
    {
        return [
            'id' => $this->id,
            'url' => null, // Changed by calling method
            'categoryCode' => $this->categoryCode,
            'title' => $this->title,
            'status' => $this->status,
            'lastUpdatedAt' => $this->updatedAt,
            'lastUpdatedAtTimespan' => $this->updatedAt->diffForHumans(),
            'isPublic' => $this->isPublic,
            'votes' => [
                'up' => $this->votesUp,
                'down' => $this->votesDown
            ],
            'from' => [
                'user' => null, // Changed by calling method
                'character' => $this->fromCharacter?->toArray(),
                'own' => $user->is($this->fromUser)
            ],
            'agent' => [
                'user' => null, // Changed by calling method
                'character' => $this->agentCharacter?->toArray(),
                'own' => $user->is($this->agentUser)
            ]
        ];
    }

    public function serializeForUserListing(User $user) : array
    {
        $array = $this->serializeForListing($user);
        $array['url'] = route('support.user.ticket', ['id' => $this->id]);
        $array['from']['user'] = $this->fromUser ? true : false;
        $array['agent']['user'] = $this->agentUser ? true : false;
        return $array;
    }

    public function serializeForAgentListing(User $user) : array
    {
        $array = $this->serializeForListing($user);
        $array['url'] = route('support.agent.ticket', ['id' => $this->id]);
        $array['from']['user'] = $this->fromUser->serializeForAdmin();
        $array['agent']['user'] = $this->agentUser?->serializeForAdmin();
        return $array;
    }


    public function serializeForUser(SupportTicketService $service) : array
    {
        return [
            'id' => $this->id
        ];
    }

    public function serializeForAgent(SupportTicketService $service) : array
    {
        $output = [
            'id' => $this->id,
            'url' => route('support.agent.ticket', ['id' => $this->id]),
            'categoryCode' => $this->categoryCode,
            'title' => $this->title,
            'content' => $this->content,
            'createdAt' => $this->createdAt,
            'createdAtTimespan' => $this->createdAt->diffForHumans(),
            'status' => $this->status,
            'statusAt' => $this->statusAt,
            'statusAtTimespan' => $this->statusAt->diffForHumans(),
            'closedAt' => $this->closedAt,
            'closedAtTimespan' => $this->closedAt?->diffForHumans(),
            'closureReason' => $this->closureReason,
            'isPublic' => $this->isPublic,
            'votes' => [
                'up' => $this->votesUp,
                'down' => $this->votesDown
            ],
            'updatedAt' => $this->updatedAt,
            'updatedAtTimespan' => $this->updatedAt->diffForHumans(),
            'from' => [
                'user' => $this->fromUser->serializeForAdmin(),
                'character' => $this->fromCharacter?->toArray()
            ],
            'agent' => [
                'user' => $this->agentUser?->serializeForAdmin(),
                'character' => $this->agentCharacter?->toArray()
            ]
        ];

        $output['log'] = array_map(function($entry) {
            return $entry->toAdminArray();
        }, $service->getLog($this));

        $output['links_from'] = [];
        $output['links_to'] = [];
        foreach ($service->getLinks($this) as $link) {
            if ($link->from->id == $this->id)
                $output['links_to'][] = $link->toAgentArray();
            else
                $output['links_from'][] = $link->toAgentArray();
        }

        $output['watchers'] = [];
        foreach ($service->getWatchers($this) as $user) {
            $output['watchers'][] = $user->serializeForAdmin();
        }
        return $output;
    }
}
