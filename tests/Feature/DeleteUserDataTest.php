<?php

use App\Models\Group;
use App\Models\Link;
use App\Models\PublicLink;
use App\Models\Tag;
use App\Models\User;
use App\Services\DeleteUserDataService;
use Illuminate\Support\Facades\DB;

function shareFor(User $user, Link|Group $subject, string $shareId): PublicLink
{
    $publicLink = PublicLink::make();

    $publicLink->user_id = $user->id;
    $publicLink->share_id = $shareId;
    $publicLink->publicLinkable()->associate($subject);
    $publicLink->save();

    return $publicLink;
}

function deleteUserData(array $options, User $user): void
{
    app(DeleteUserDataService::class)->deleteUserData($options, $user);
}

test('deleting links removes them permanently, archived ones included', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);
    $archived = Link::factory()->create(['user_id' => $user->id]);
    $archived->delete();

    deleteUserData(['links'], $user);

    expect(Link::withTrashed()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Link::withTrashed()->find($link->id))->toBeNull()
        ->and(Link::withTrashed()->find($archived->id))->toBeNull();
});

test('deleting links takes their tags, group filings and shares with them', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create(['user_id' => $user->id]);
    $link = Link::factory()->create(['user_id' => $user->id]);
    $link->attachTags(['laravel']);
    $link->groups()->attach($group->id);
    shareFor($user, $link, 'link-share');

    deleteUserData(['links'], $user);

    expect(Group::find($group->id))->not->toBeNull()
        ->and(DB::table('groupables')->where('group_id', $group->id)->count())->toBe(0)
        ->and(DB::table('taggables')->where('taggable_id', $link->id)->count())->toBe(0)
        ->and(PublicLink::where('share_id', 'link-share')->exists())->toBeFalse()
        ->and(Tag::query()->count())->toBe(0);
});

test('deleting one category leaves the others standing', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);
    $link->attachTags(['php']);
    $group = Group::factory()->create(['user_id' => $user->id]);

    deleteUserData(['groups'], $user);

    expect(Group::where('user_id', $user->id)->count())->toBe(0)
        ->and(Link::find($link->id))->not->toBeNull()
        ->and($link->fresh()->tags)->toHaveCount(1);
});

test('deleting data never reaches into another account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Link::factory()->create(['user_id' => $user->id]);
    Group::factory()->create(['user_id' => $user->id]);
    $otherLink = Link::factory()->create(['user_id' => $other->id]);
    $otherGroup = Group::factory()->create(['user_id' => $other->id]);
    shareFor($other, $otherGroup, 'other-share');

    deleteUserData(['links', 'groups', 'tags', 'shares'], $user);

    expect(Link::find($otherLink->id))->not->toBeNull()
        ->and(Group::find($otherGroup->id))->not->toBeNull()
        ->and(PublicLink::where('share_id', 'other-share')->exists())->toBeTrue();
});

test('deleting groups keeps the links that were filed in them', function () {
    $user = User::factory()->create();
    $parent = Group::factory()->create(['user_id' => $user->id]);
    $child = Group::factory()->create(['user_id' => $user->id, 'parent_group_id' => $parent->id]);
    $link = Link::factory()->create(['user_id' => $user->id]);
    $link->groups()->attach($child->id);
    shareFor($user, $parent, 'group-share');

    deleteUserData(['groups'], $user);

    expect(Group::whereIn('id', [$parent->id, $child->id])->count())->toBe(0)
        ->and(Link::find($link->id))->not->toBeNull()
        ->and(PublicLink::where('share_id', 'group-share')->exists())->toBeFalse();
});

test('deleting tags strips them from links without deleting the links', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);
    $link->attachTags(['laravel', 'php']);
    $archived = Link::factory()->create(['user_id' => $user->id]);
    $archived->attachTags(['ansible']);
    $archived->delete();

    deleteUserData(['tags'], $user);

    expect(Link::find($link->id))->not->toBeNull()
        ->and($link->fresh()->tags)->toHaveCount(0)
        ->and(Link::withTrashed()->find($archived->id)->tags)->toHaveCount(0)
        ->and(Tag::query()->count())->toBe(0);
});

test('deleting tags leaves rows another account still uses', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $link = Link::factory()->create(['user_id' => $user->id]);
    $link->attachTags(['laravel']);

    $otherLink = Link::factory()->create(['user_id' => $other->id]);
    $otherLink->attachTags(['laravel']);

    deleteUserData(['tags'], $user);

    expect($link->fresh()->tags)->toHaveCount(0)
        ->and($otherLink->fresh()->tags)->toHaveCount(1)
        ->and(Tag::query()->count())->toBe(1);
});

test('a tag held only by another account archived link is not swept up as an orphan', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $link = Link::factory()->create(['user_id' => $user->id]);
    $link->attachTags(['laravel']);

    $otherLink = Link::factory()->create(['user_id' => $other->id]);
    $otherLink->attachTags(['laravel']);
    $otherLink->delete();

    deleteUserData(['links'], $user);

    expect(Tag::query()->count())->toBe(1)
        ->and(Link::withTrashed()->find($otherLink->id)->tags)->toHaveCount(1);
});

test('deleting tags rewrites the smart group rules that named them', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);
    $link->attachTags(['laravel']);
    $tagId = $link->tags->first()->id;

    $group = Group::factory()
        ->withTagRules(orTags: [$tagId])
        ->create(['user_id' => $user->id]);

    deleteUserData(['tags'], $user);

    expect($group->fresh()->query_options['containsTagsOr'])->toBe([]);
});

test('deleting shares revokes every published page but keeps the content', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);
    $group = Group::factory()->create(['user_id' => $user->id]);
    shareFor($user, $link, 'a-link-share');
    shareFor($user, $group, 'a-group-share');

    deleteUserData(['shares'], $user);

    expect(PublicLink::where('user_id', $user->id)->count())->toBe(0)
        ->and(Link::find($link->id))->not->toBeNull()
        ->and(Group::find($group->id))->not->toBeNull();
});

test('deleting links refreshes the cached link count on groups', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create(['user_id' => $user->id]);
    $link = Link::factory()->create(['user_id' => $user->id]);
    $link->groups()->attach($group->id);
    $group->updateLinksCount();
    $group->save();

    expect($group->fresh()->links_count)->toBe(1);

    deleteUserData(['links'], $user);

    expect($group->fresh()->links_count)->toBe(0);
});

test('an empty or unrecognised option list deletes nothing', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);
    $group = Group::factory()->create(['user_id' => $user->id]);

    deleteUserData([], $user);
    deleteUserData(['everything'], $user);

    expect(Link::find($link->id))->not->toBeNull()
        ->and(Group::find($group->id))->not->toBeNull();
});

test('the route deletes the signed-in user data and sends them back', function () {
    $user = User::factory()->create();
    Link::factory()->create(['user_id' => $user->id]);
    Group::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->from(route('links.index'))
        ->post(route('delete-user-data'), ['deleteOptions' => ['links']])
        ->assertRedirect(route('links.index'));

    expect(Link::withTrashed()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Group::where('user_id', $user->id)->count())->toBe(1);
});

test('the route rejects an option that names nothing', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('delete-user-data'), ['deleteOptions' => ['account']])
        ->assertSessionHasErrors('deleteOptions.0');

    expect(Link::find($link->id))->not->toBeNull();
});

test('guests cannot delete anyone data', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);

    $this->post(route('delete-user-data'), ['deleteOptions' => ['links']])
        ->assertRedirect(route('login'));

    expect(Link::find($link->id))->not->toBeNull();
});
