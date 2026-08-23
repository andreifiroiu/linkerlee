<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ExportService
{
    /**
     * The shape this service writes. `ImportService` reads it back, and treats a
     * payload without a version as one of the older, thinner files.
     */
    public const FORMAT_VERSION = 1;

    /**
     * Everything in a user's library, in a form that can be imported back whole.
     *
     * `page_text` is the one column deliberately left out: it holds the entire
     * fetched page body to feed search, it can run to megabytes a link, and
     * `FetchLinkMetadataJob` regenerates it. Nothing a user typed is dropped.
     *
     * @return array<string, mixed>
     */
    public function exportUserData(User $user): array
    {
        $groups = Group::where('user_id', $user->id)->get();

        return [
            'version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'links' => $this->links($user),
            'tags' => $this->tags($user),
            'groups' => $this->groups($groups),
        ];
    }

    /**
     * Every link the user has, archived ones included.
     *
     * Read in chunks: a whole library of hydrated Eloquent models is what would
     * actually run a large account out of memory, and the plain arrays this
     * leaves behind are comparatively tiny.
     *
     * @return array<int, array<string, mixed>>
     */
    private function links(User $user): array
    {
        $links = [];

        Link::withTrashed()
            ->where('user_id', $user->id)
            ->with(['tags', 'groups'])
            ->chunkById(500, function (Collection $chunk) use (&$links): void {
                foreach ($chunk as $link) {
                    $links[] = [
                        'link' => $link->link,
                        'title' => $link->title,
                        'description' => $link->description,
                        'tags' => $link->tags->pluck('name')->toArray(),
                        'group_ids' => $link->groups->pluck('id')->toArray(),
                        'is_favorite' => (bool) $link->is_favorite,
                        'rating' => $link->rating,
                        'read_at' => $link->read_at?->toIso8601String(),
                        'archived' => $link->trashed(),
                        'source' => $link->source?->value,
                        'favicon_url' => $link->favicon_url,
                        'preview_image_url' => $link->preview_image_url,
                        'metadata_fetched_at' => $link->metadata_fetched_at?->toIso8601String(),
                        'created_at' => $link->created_at?->toIso8601String(),
                        'updated_at' => $link->updated_at?->toIso8601String(),
                    ];
                }
            });

        return $links;
    }

    /**
     * The user's tags, kept as a top-level list for the sake of older importers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tags(User $user): array
    {
        return Tag::filterByUser($user->id)
            ->get()
            ->map(fn (Tag $tag) => ['name' => $tag->name])
            ->toArray();
    }

    /**
     * The user's collections, carrying their nesting and their tag rules.
     *
     * `id` is the row's own id, and means nothing outside this file: it is here
     * only so links and child collections have something unambiguous to point
     * at. Titles could not do that job — two collections may share one, and a
     * title may itself contain whatever separator a path would need.
     *
     * @param  Collection<int, Group>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function groups(Collection $groups): array
    {
        $tagNamesById = $this->ruleTagNamesById($groups);

        return $groups->map(fn (Group $group) => [
            'id' => $group->id,
            'parent_id' => $group->parent_group_id,
            'title' => $group->title,
            'query_options' => $this->queryOptionsAsTagNames($group, $tagNamesById),
        ])->values()->toArray();
    }

    /**
     * Smart-collection rules store tag ids, which say nothing outside the
     * database they came from — and tag rows are shared between accounts, so
     * the id cannot simply be carried over. Names travel; ids do not.
     *
     * @param  array<int, string>  $tagNamesById
     * @return array<string, array<int, string>>
     */
    private function queryOptionsAsTagNames(Group $group, array $tagNamesById): array
    {
        $queryOptions = $group->query_options ?? [];
        $named = [];

        foreach (Group::TAG_RULE_KEYS as $key) {
            $names = [];

            foreach ($queryOptions[$key] ?? [] as $tagId) {
                if (isset($tagNamesById[$tagId])) {
                    $names[] = $tagNamesById[$tagId];
                }
            }

            if ($names !== []) {
                $named[$key] = $names;
            }
        }

        return $named;
    }

    /**
     * Look up every tag named by any collection's rules in one query.
     *
     * @param  Collection<int, Group>  $groups
     * @return array<int, string>
     */
    private function ruleTagNamesById(Collection $groups): array
    {
        $tagIds = $groups->flatMap(fn (Group $group) => $group->ruleTagIds())->unique()->all();

        if ($tagIds === []) {
            return [];
        }

        return Tag::whereIn('id', $tagIds)
            ->get()
            ->mapWithKeys(fn (Tag $tag) => [$tag->id => (string) $tag->name])
            ->all();
    }
}
