<?php

namespace App\Services;

use App\Enums\UserDataType;
use App\Models\Group;
use App\Models\Link;
use App\Models\PublicLink;
use App\Models\Tag;
use App\Models\User;
use App\Services\Models\GroupService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DeleteUserDataService
{
    public function __construct(
        protected GroupService $groupService,
    ) {
        //
    }

    /**
     * Permanently wipe whole categories of a user's data, leaving the account itself intact.
     *
     * This is the destructive counterpart to an import: the same option names,
     * and the same "only the categories you asked for" contract. Unrecognised
     * options are ignored rather than rejected, because the caller has already
     * validated them and a stray value should not cost the user the rest of the
     * request. Everything runs in one transaction, so a failure part-way
     * through cannot leave an account half-emptied.
     *
     * @param  array<int, string>  $deleteOptions
     */
    public function deleteUserData(array $deleteOptions, User $user): void
    {
        $requested = array_filter(array_map(
            fn (string $option): ?UserDataType => UserDataType::tryFrom($option),
            $deleteOptions,
        ));

        if ($requested === []) {
            return;
        }

        DB::transaction(function () use ($requested, $user): void {
            foreach (UserDataType::cases() as $type) {
                if (! in_array($type, $requested, true)) {
                    continue;
                }

                match ($type) {
                    UserDataType::Links => $this->deleteLinks($user),
                    UserDataType::Groups => $this->deleteGroups($user),
                    UserDataType::Tags => $this->deleteTags($user),
                    UserDataType::Shares => $this->deleteShares($user),
                };
            }

            $this->groupService->updateUserGroupsLinkCount($user);
        });
    }

    /**
     * Force-delete every link, archived ones included.
     *
     * The trash is not spared: a user asking for their links to be deleted is
     * not asking for them to be moved somewhere they can still be read. Each
     * row is deleted through the model rather than in bulk so the `forceDeleted`
     * hook fires and takes the tag attachments with it; the group pivot and the
     * share have no cascade of their own, hence the explicit cleanup.
     */
    private function deleteLinks(User $user): void
    {
        Link::withTrashed()
            ->where('user_id', $user->id)
            ->chunkById(100, function (Collection $links): void {
                foreach ($links as $link) {
                    $link->groups()->detach();
                    $link->forceDelete();
                }
            });

        PublicLink::where('user_id', $user->id)
            ->where('public_linkable_type', Link::class)
            ->delete();

        $this->deleteOrphanedTags();
    }

    /**
     * Delete every group, leaving the links that were filed in them alone.
     *
     * Groups nest through a self-referential key, so the nesting is cleared
     * before anything is deleted — that way the rows can go in any order the
     * database likes.
     */
    private function deleteGroups(User $user): void
    {
        $groups = Group::where('user_id', $user->id)->get();

        if ($groups->isEmpty()) {
            return;
        }

        PublicLink::where('user_id', $user->id)
            ->where('public_linkable_type', Group::class)
            ->delete();

        foreach ($groups as $group) {
            $group->directLinks()->detach();
        }

        Group::where('user_id', $user->id)->update(['parent_group_id' => null]);

        Group::where('user_id', $user->id)->delete();
    }

    /**
     * Strip every tag from the user's links, keeping the links themselves.
     *
     * Archived links are included, because they hold on to their tags for the
     * day they are restored. Any smart group whose rules named one of these
     * tags is rewritten too, or it would go on filtering by a tag the user can
     * no longer see.
     */
    private function deleteTags(User $user): void
    {
        $detachedTagIds = [];

        Link::withTrashed()
            ->where('user_id', $user->id)
            ->has('tags')
            ->with('tags')
            ->chunkById(100, function (Collection $links) use (&$detachedTagIds): void {
                foreach ($links as $link) {
                    $detachedTagIds = [...$detachedTagIds, ...$link->tags->pluck('id')->all()];

                    $link->detachTags($link->tags);
                }
            });

        $this->groupService->removeDeletedTagsFromQueryOptions(array_unique($detachedTagIds), $user);

        $this->deleteOrphanedTags();
    }

    /**
     * Revoke every public share page the user has published.
     */
    private function deleteShares(User $user): void
    {
        PublicLink::where('user_id', $user->id)->delete();
    }

    /**
     * Drop tag rows that nothing points at any more.
     *
     * Tags carry no owner: two people who both tag something "recipes" share a
     * single row. So one user's data being deleted can only ever detach that
     * user's links from it — the row itself goes when, and only when, no link
     * anywhere still uses it. Archived links count as users of a tag, since
     * restoring one is meant to bring its tags back with it.
     */
    private function deleteOrphanedTags(): void
    {
        Tag::query()
            ->whereDoesntHave('links', function (Builder $query): void {
                $query->withTrashed();
            })
            ->delete();
    }
}
