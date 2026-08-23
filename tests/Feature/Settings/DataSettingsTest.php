<?php

use App\Models\Group;
use App\Models\Link;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;

test('the data settings page reports what an export would contain', function () {
    $user = User::factory()->create();
    Link::factory()->count(2)->create(['user_id' => $user->id]);
    $archived = Link::factory()->create(['user_id' => $user->id]);
    $archived->delete();
    Group::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('data.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/data')
            ->where('counts.links', 2)
            ->where('counts.archivedLinks', 1)
            ->where('counts.groups', 1)
            ->has('csrfToken')
        );
});

test('guests cannot reach the data settings page', function () {
    $this->get(route('data.edit'))->assertRedirect(route('login'));
});

test('exporting as json streams a json download', function () {
    $user = User::factory()->create();
    Link::factory()->create(['user_id' => $user->id, 'link' => 'https://exported.test']);

    $response = $this->actingAs($user)->post(route('export'), ['exportFormat' => 'json']);

    $response->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertDownload('export.json');

    $payload = json_decode($response->streamedContent(), true);

    expect($payload['version'])->toBe(1)
        ->and($payload['links'][0]['link'])->toBe('https://exported.test');
});

test('exporting as html streams a bookmarks file', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create(['user_id' => $user->id, 'title' => 'Recipes']);
    $link = Link::factory()->create(['user_id' => $user->id, 'link' => 'https://exported.test']);
    $link->groups()->attach($group->id);
    $link->attachTags(['food']);

    $response = $this->actingAs($user)->post(route('export'), ['exportFormat' => 'html']);

    $response->assertOk()->assertDownload('export.html');

    expect($response->streamedContent())
        ->toContain('NETSCAPE-Bookmark-file-1')
        ->toContain('<H3>Recipes</H3>')
        ->toContain('TAGS="food"');
});

test('an export names a format the application knows', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('export'), ['exportFormat' => 'pdf'])
        ->assertSessionHasErrors('exportFormat');
});

test('guests cannot export', function () {
    $this->post(route('export'), ['exportFormat' => 'json'])
        ->assertRedirect(route('login'));
});

test('a json export uploaded to import restores the library', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create(['user_id' => $user->id, 'title' => 'Reading']);
    $link = Link::factory()->create([
        'user_id' => $user->id,
        'link' => 'https://restored.test',
        'description' => 'Kept through the round trip.',
    ]);
    $link->groups()->attach($group->id);
    $link->attachTags(['php']);

    $exported = $this->actingAs($user)
        ->post(route('export'), ['exportFormat' => 'json'])
        ->streamedContent();

    $other = User::factory()->create();

    $this->actingAs($other)
        ->post(route('import'), [
            'importSource' => 'json',
            'importOptions' => ['links', 'groups'],
            'importFile' => UploadedFile::fake()
                ->createWithContent('export.json', $exported),
        ])
        ->assertRedirect();

    $restored = Link::where('user_id', $other->id)->where('link', 'https://restored.test')->first();

    expect($restored)->not->toBeNull()
        ->and($restored->description)->toBe('Kept through the round trip.')
        ->and($restored->tags->pluck('name')->all())->toBe(['php'])
        ->and($restored->groups->pluck('title')->all())->toBe(['Reading']);
});
