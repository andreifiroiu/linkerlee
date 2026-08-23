<?php

use App\Enums\LinkSource;
use App\Models\Group;
use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use App\Services\ExportService;
use App\Services\HtmlBookmarkExportService;
use App\Services\HtmlBookmarkImportService;
use App\Services\ImportService;

test('export service returns links tags and groups', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);
    Group::factory()->create(['user_id' => $user->id]);

    $export = app(ExportService::class)->exportUserData($user);

    expect($export)->toHaveKey('links')
        ->and($export)->toHaveKey('tags')
        ->and($export)->toHaveKey('groups')
        ->and($export['links'])->toHaveCount(1)
        ->and($export['links'][0]['link'])->toBe($link->link);
});

test('html bookmark export service creates valid netscape html', function () {
    $export = ['links' => [['title' => 'Example', 'link' => 'https://example.com', 'tags' => []]]];
    $html = app(HtmlBookmarkExportService::class)->createHtmlExport($export);

    expect($html)->toContain('NETSCAPE-Bookmark-file-1')
        ->and($html)->toContain('https://example.com')
        ->and($html)->toContain('Example');
});

test('html bookmark import service extracts links from netscape html', function () {
    $html = '<!DOCTYPE NETSCAPE-Bookmark-file-1><DL><p><DT><A HREF="https://example.com">Example</A></DL>';

    $data = app(HtmlBookmarkImportService::class)->extractData($html);

    expect($data)->toHaveKey('links')
        ->and($data['links'])->toHaveCount(1)
        ->and($data['links'][0]['link'])->toBe('https://example.com')
        ->and($data['links'][0]['title'])->toBe('Example');
});

test('import service creates links for user', function () {
    $user = User::factory()->create();
    $data = [
        'links' => [
            ['title' => 'Test', 'link' => 'https://test.com', 'tags' => []],
        ],
    ];

    app(ImportService::class)->importUserData($data, ['links'], $user);

    expect(Link::where('user_id', $user->id)->where('link', 'https://test.com')->exists())->toBeTrue();
});

test('import service skips duplicate links', function () {
    $user = User::factory()->create();
    Link::factory()->create(['user_id' => $user->id, 'link' => 'https://dupe.com']);
    $data = [
        'links' => [
            ['title' => 'Dupe', 'link' => 'https://dupe.com', 'tags' => []],
        ],
    ];

    app(ImportService::class)->importUserData($data, ['links'], $user);

    expect(Link::where('user_id', $user->id)->where('link', 'https://dupe.com')->count())->toBe(1);
});

test('export import round trip preserves links', function () {
    $user = User::factory()->create();
    Link::factory()->create(['user_id' => $user->id, 'title' => 'Round Trip', 'link' => 'https://roundtrip.com']);

    $export = app(ExportService::class)->exportUserData($user);

    $otherUser = User::factory()->create();
    app(ImportService::class)->importUserData($export, ['links', 'groups'], $otherUser);

    expect(Link::where('user_id', $otherUser->id)->where('link', 'https://roundtrip.com')->exists())->toBeTrue();
});

test('import service skips links longer than the column allows', function () {
    $user = User::factory()->create();
    $data = [
        'links' => [
            ['title' => 'Too long', 'link' => 'https://example.com/?q='.str_repeat('a', 2100), 'tags' => []],
            ['title' => 'Fine', 'link' => 'https://example.com/ok', 'tags' => []],
        ],
    ];

    app(ImportService::class)->importUserData($data, ['links'], $user);

    expect(Link::where('user_id', $user->id)->pluck('link')->all())->toBe(['https://example.com/ok']);
});

test('export carries every field a user can set on a link', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create([
        'user_id' => $user->id,
        'title' => 'Full Fidelity',
        'link' => 'https://fidelity.test',
        'description' => 'A description worth keeping.',
        'is_favorite' => true,
        'rating' => 4,
        'read_at' => now()->subDay(),
        'source' => LinkSource::Extension,
        'favicon_url' => 'https://fidelity.test/favicon.ico',
    ]);
    $link->attachTags(['php']);

    $export = app(ExportService::class)->exportUserData($user);
    $exported = $export['links'][0];

    expect($export['version'])->toBe(1)
        ->and($export)->toHaveKey('exported_at')
        ->and($exported['description'])->toBe('A description worth keeping.')
        ->and($exported['is_favorite'])->toBeTrue()
        ->and($exported['rating'])->toBe(4)
        ->and($exported['read_at'])->not->toBeNull()
        ->and($exported['source'])->toBe('extension')
        ->and($exported['favicon_url'])->toBe('https://fidelity.test/favicon.ico')
        ->and($exported['tags'])->toBe(['php'])
        ->and($exported['archived'])->toBeFalse();
});

test('export never carries the cached page text', function () {
    $user = User::factory()->create();
    Link::factory()->create([
        'user_id' => $user->id,
        'page_text' => 'the entire body of the fetched page',
    ]);

    $export = app(ExportService::class)->exportUserData($user);

    expect($export['links'][0])->not->toHaveKey('page_text')
        ->and(json_encode($export))->not->toContain('the entire body of the fetched page');
});

test('a round trip restores every link field into another account', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create([
        'user_id' => $user->id,
        'title' => 'Restore Me',
        'link' => 'https://restore.test',
        'description' => 'Kept.',
        'is_favorite' => true,
        'rating' => 5,
        'read_at' => now()->subWeek(),
        'source' => LinkSource::Email,
        'created_at' => now()->subYear(),
    ]);
    $link->attachTags(['laravel', 'php']);

    $export = app(ExportService::class)->exportUserData($user);

    $other = User::factory()->create();
    app(ImportService::class)->importUserData($export, ['links', 'groups'], $other);

    $restored = Link::where('user_id', $other->id)->where('link', 'https://restore.test')->first();

    expect($restored)->not->toBeNull()
        ->and($restored->title)->toBe('Restore Me')
        ->and($restored->description)->toBe('Kept.')
        ->and($restored->is_favorite)->toBeTrue()
        ->and($restored->rating)->toBe(5)
        ->and($restored->read_at)->not->toBeNull()
        ->and($restored->source)->toBe(LinkSource::Email)
        ->and($restored->created_at->year)->toBe(now()->subYear()->year)
        ->and($restored->tags->pluck('name')->sort()->values()->all())->toBe(['laravel', 'php']);
});

test('a round trip restores which collection each link was filed in', function () {
    $user = User::factory()->create();
    $parent = Group::factory()->create(['user_id' => $user->id, 'title' => 'Reading']);
    $child = Group::factory()->create([
        'user_id' => $user->id,
        'title' => 'Backend',
        'parent_group_id' => $parent->id,
    ]);
    $link = Link::factory()->create(['user_id' => $user->id, 'link' => 'https://filed.test']);
    $link->groups()->attach($child->id);

    $export = app(ExportService::class)->exportUserData($user);

    $other = User::factory()->create();
    app(ImportService::class)->importUserData($export, ['links', 'groups'], $other);

    $restoredChild = Group::where('user_id', $other->id)->where('title', 'Backend')->first();
    $restoredParent = Group::where('user_id', $other->id)->where('title', 'Reading')->first();
    $restoredLink = Link::where('user_id', $other->id)->where('link', 'https://filed.test')->first();

    expect($restoredChild->parent_group_id)->toBe($restoredParent->id)
        ->and($restoredLink->groups->pluck('id')->all())->toBe([$restoredChild->id]);
});

test('a round trip restores smart collection rules by tag name', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id]);
    $link->attachTags(['php']);
    $tagId = $link->tags->first()->id;

    Group::factory()
        ->withTagRules(orTags: [$tagId])
        ->create(['user_id' => $user->id, 'title' => 'Smart']);

    $export = app(ExportService::class)->exportUserData($user);

    expect($export['groups'][0]['query_options']['containsTagsOr'])->toBe(['php']);

    $other = User::factory()->create();
    app(ImportService::class)->importUserData($export, ['links', 'groups'], $other);

    $restored = Group::where('user_id', $other->id)->where('title', 'Smart')->first();
    $ruleTagIds = $restored->query_options['containsTagsOr'];

    expect($ruleTagIds)->toHaveCount(1)
        ->and(Tag::find($ruleTagIds[0])->name)->toBe('php');
});

test('an archived link survives a round trip as archived', function () {
    $user = User::factory()->create();
    $archived = Link::factory()->create(['user_id' => $user->id, 'link' => 'https://archived.test']);
    $archived->delete();

    $export = app(ExportService::class)->exportUserData($user);

    expect($export['links'][0]['archived'])->toBeTrue();

    $other = User::factory()->create();
    app(ImportService::class)->importUserData($export, ['links', 'groups'], $other);

    $restored = Link::withTrashed()->where('user_id', $other->id)->where('link', 'https://archived.test')->first();

    expect($restored)->not->toBeNull()
        ->and($restored->trashed())->toBeTrue()
        ->and(Link::where('user_id', $other->id)->count())->toBe(0);
});

test('a legacy export with no version still imports', function () {
    $user = User::factory()->create();

    $legacy = [
        'links' => [
            ['title' => 'Old', 'link' => 'https://legacy.test', 'tags' => ['archive']],
        ],
        'tags' => [['name' => 'archive']],
        'groups' => [['title' => 'Old Collection']],
    ];

    app(ImportService::class)->importUserData($legacy, ['links', 'groups'], $user);

    $link = Link::where('user_id', $user->id)->where('link', 'https://legacy.test')->first();

    expect($link)->not->toBeNull()
        ->and($link->title)->toBe('Old')
        ->and($link->source)->toBe(LinkSource::Import)
        ->and($link->tags->pluck('name')->all())->toBe(['archive'])
        ->and(Group::where('user_id', $user->id)->where('title', 'Old Collection')->exists())->toBeTrue();
});

test('import ignores a collection that claims to be its own ancestor', function () {
    $user = User::factory()->create();

    $data = [
        'version' => 1,
        'groups' => [
            ['id' => 1, 'parent_id' => 2, 'title' => 'A'],
            ['id' => 2, 'parent_id' => 1, 'title' => 'B'],
        ],
        'links' => [],
    ];

    app(ImportService::class)->importUserData($data, ['links', 'groups'], $user);

    $groups = Group::where('user_id', $user->id)->get();
    $parents = $groups->pluck('parent_group_id')->filter()->values();

    expect($groups)->toHaveCount(2)
        ->and($parents)->toHaveCount(1);
});

test('html export writes folders, tags, dates and notes', function () {
    $export = [
        'version' => 1,
        'groups' => [
            ['id' => 1, 'parent_id' => null, 'title' => 'Reading'],
            ['id' => 2, 'parent_id' => 1, 'title' => 'Backend'],
        ],
        'links' => [
            [
                'link' => 'https://nested.test',
                'title' => 'Nested',
                'description' => 'A note',
                'tags' => ['php', 'laravel'],
                'group_ids' => [2],
                'created_at' => '2026-01-01T00:00:00+00:00',
                'updated_at' => null,
            ],
        ],
    ];

    $html = app(HtmlBookmarkExportService::class)->createHtmlExport($export);

    expect($html)->toContain('<H3>Reading</H3>')
        ->and($html)->toContain('<H3>Backend</H3>')
        ->and($html)->toContain('TAGS="php,laravel"')
        ->and($html)->toContain('ADD_DATE="'.strtotime('2026-01-01T00:00:00+00:00').'"')
        ->and($html)->toContain('<DD>A note');
});

test('an html round trip keeps folder nesting and tags', function () {
    $export = [
        'version' => 1,
        'groups' => [
            ['id' => 1, 'parent_id' => null, 'title' => 'Reading'],
            ['id' => 2, 'parent_id' => 1, 'title' => 'Backend'],
        ],
        'links' => [
            [
                'link' => 'https://nested.test',
                'title' => 'Nested',
                'description' => 'A note',
                'tags' => ['php'],
                'group_ids' => [2],
                'created_at' => null,
                'updated_at' => null,
            ],
            [
                'link' => 'https://loose.test',
                'title' => 'Loose',
                'description' => null,
                'tags' => [],
                'group_ids' => [],
                'created_at' => null,
                'updated_at' => null,
            ],
        ],
    ];

    $html = app(HtmlBookmarkExportService::class)->createHtmlExport($export);
    $parsed = app(HtmlBookmarkImportService::class)->extractData($html);

    $backend = collect($parsed['groups'])->firstWhere('title', 'Backend');
    $reading = collect($parsed['groups'])->firstWhere('title', 'Reading');
    $nested = collect($parsed['links'])->firstWhere('link', 'https://nested.test');
    $loose = collect($parsed['links'])->firstWhere('link', 'https://loose.test');

    expect($backend['parent_id'])->toBe($reading['id'])
        ->and($nested['group_ids'])->toBe([$backend['id']])
        ->and($nested['tags'])->toBe(['php'])
        ->and($nested['description'])->toBe('A note')
        ->and($loose['group_ids'])->toBe([]);
});

test('a browser bookmarks file with unclosed tags still yields its folders', function () {
    $html = <<<'HTML'
    <!DOCTYPE NETSCAPE-Bookmark-file-1>
    <TITLE>Bookmarks</TITLE>
    <H1>Bookmarks Menu</H1>
    <DL><p>
        <DT><H3 PERSONAL_TOOLBAR_FOLDER="true">Bookmarks Toolbar</H3>
        <DL><p>
            <DT><A HREF="https://toolbar.test" ADD_DATE="1700000000" TAGS="news,daily">Toolbar Link</A>
            <DT><H3>Nested Folder</H3>
            <DL><p>
                <DT><A HREF="https://deep.test">Deep Link</A>
            </DL><p>
        </DL><p>
        <DT><A HREF="https://root.test">Root Link</A>
    </DL>
    HTML;

    $parsed = app(HtmlBookmarkImportService::class)->extractData($html);

    $toolbar = collect($parsed['groups'])->firstWhere('title', 'Bookmarks Toolbar');
    $nested = collect($parsed['groups'])->firstWhere('title', 'Nested Folder');
    $toolbarLink = collect($parsed['links'])->firstWhere('link', 'https://toolbar.test');
    $deepLink = collect($parsed['links'])->firstWhere('link', 'https://deep.test');
    $rootLink = collect($parsed['links'])->firstWhere('link', 'https://root.test');

    expect($parsed['groups'])->toHaveCount(2)
        ->and($nested['parent_id'])->toBe($toolbar['id'])
        ->and($toolbarLink['tags'])->toBe(['news', 'daily'])
        ->and($toolbarLink['created_at'])->not->toBeNull()
        ->and($toolbarLink['group_ids'])->toBe([$toolbar['id']])
        ->and($deepLink['group_ids'])->toBe([$nested['id']])
        ->and($rootLink['group_ids'])->toBe([]);
});

test('importing a browser bookmarks file creates the folders as collections', function () {
    $user = User::factory()->create();

    $html = <<<'HTML'
    <!DOCTYPE NETSCAPE-Bookmark-file-1>
    <DL><p>
        <DT><H3>Recipes</H3>
        <DL><p>
            <DT><A HREF="https://recipe.test" TAGS="food">Dinner</A>
        </DL><p>
    </DL>
    HTML;

    $data = app(HtmlBookmarkImportService::class)->extractData($html);
    app(ImportService::class)->importUserData($data, ['links', 'groups'], $user);

    $group = Group::where('user_id', $user->id)->where('title', 'Recipes')->first();
    $link = Link::where('user_id', $user->id)->where('link', 'https://recipe.test')->first();

    expect($group)->not->toBeNull()
        ->and($link)->not->toBeNull()
        ->and($link->tags->pluck('name')->all())->toBe(['food'])
        ->and($link->groups->pluck('id')->all())->toBe([$group->id]);
});

test('collections with the same title in different parents stay separate', function () {
    $user = User::factory()->create();

    $data = [
        'version' => 1,
        'groups' => [
            ['id' => 1, 'parent_id' => null, 'title' => 'Reading'],
            ['id' => 2, 'parent_id' => null, 'title' => 'Work'],
            ['id' => 3, 'parent_id' => 1, 'title' => 'Backend'],
            ['id' => 4, 'parent_id' => 2, 'title' => 'Backend'],
        ],
        'links' => [
            ['link' => 'https://reading.test', 'title' => 'R', 'tags' => [], 'group_ids' => [3]],
            ['link' => 'https://work.test', 'title' => 'W', 'tags' => [], 'group_ids' => [4]],
        ],
    ];

    app(ImportService::class)->importUserData($data, ['links', 'groups'], $user);

    $reading = Group::where('user_id', $user->id)->where('title', 'Reading')->first();
    $work = Group::where('user_id', $user->id)->where('title', 'Work')->first();
    $backends = Group::where('user_id', $user->id)->where('title', 'Backend')->get();

    $readingBackend = $backends->firstWhere('parent_group_id', $reading->id);
    $workBackend = $backends->firstWhere('parent_group_id', $work->id);

    expect($backends)->toHaveCount(2)
        ->and($readingBackend)->not->toBeNull()
        ->and($workBackend)->not->toBeNull()
        ->and(Link::where('link', 'https://reading.test')->first()->groups->pluck('id')->all())
        ->toBe([$readingBackend->id])
        ->and(Link::where('link', 'https://work.test')->first()->groups->pluck('id')->all())
        ->toBe([$workBackend->id]);
});

test('a collection listed before its parent still ends up nested', function () {
    $user = User::factory()->create();

    $data = [
        'version' => 1,
        'groups' => [
            ['id' => 2, 'parent_id' => 1, 'title' => 'Child'],
            ['id' => 1, 'parent_id' => null, 'title' => 'Parent'],
        ],
        'links' => [],
    ];

    app(ImportService::class)->importUserData($data, ['links', 'groups'], $user);

    $parent = Group::where('user_id', $user->id)->where('title', 'Parent')->first();
    $child = Group::where('user_id', $user->id)->where('title', 'Child')->first();

    expect($child->parent_group_id)->toBe($parent->id);
});

test('importing the same file twice does not duplicate collections', function () {
    $user = User::factory()->create();

    $data = [
        'version' => 1,
        'groups' => [
            ['id' => 1, 'parent_id' => null, 'title' => 'Reading'],
            ['id' => 2, 'parent_id' => 1, 'title' => 'Backend'],
        ],
        'links' => [
            ['link' => 'https://twice.test', 'title' => 'Twice', 'tags' => [], 'group_ids' => [2]],
        ],
    ];

    app(ImportService::class)->importUserData($data, ['links', 'groups'], $user);
    app(ImportService::class)->importUserData($data, ['links', 'groups'], $user);

    expect(Group::where('user_id', $user->id)->count())->toBe(2)
        ->and(Link::where('user_id', $user->id)->count())->toBe(1);
});

test('importing adds tags to a link the user already has, without dropping its own', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id, 'link' => 'https://example.com']);
    $link->attachTags(['php', 'favourite', 'read-later']);

    $data = [
        'links' => [
            ['link' => 'https://example.com', 'title' => 'Theirs', 'tags' => ['php', 'laravel']],
        ],
    ];

    app(ImportService::class)->importUserData($data, ['links'], $user);

    expect($link->fresh()->tags->pluck('name')->sort()->values()->all())
        ->toBe(['favourite', 'laravel', 'php', 'read-later']);
});

test('importing a file with no tags leaves an existing link tags alone', function () {
    $user = User::factory()->create();
    $link = Link::factory()->create(['user_id' => $user->id, 'link' => 'https://example.com']);
    $link->attachTags(['php', 'favourite']);

    $data = [
        'links' => [
            ['link' => 'https://example.com', 'title' => 'Bookmark', 'tags' => []],
        ],
    ];

    app(ImportService::class)->importUserData($data, ['links'], $user);

    expect($link->fresh()->tags->pluck('name')->sort()->values()->all())
        ->toBe(['favourite', 'php']);
});

test('an over-long folder name is cut to fit rather than failing the import', function () {
    $user = User::factory()->create();
    $longTitle = str_repeat('A', 400);

    $html = '<!DOCTYPE NETSCAPE-Bookmark-file-1><DL><p>'
        ."<DT><H3>{$longTitle}</H3>"
        .'<DL><p><DT><A HREF="https://deep.test">Deep</A></DL><p>'
        .'</DL>';

    $data = app(HtmlBookmarkImportService::class)->extractData($html);
    app(ImportService::class)->importUserData($data, ['links', 'groups'], $user);

    $group = Group::where('user_id', $user->id)->first();

    expect($group)->not->toBeNull()
        ->and(mb_strlen($group->title))->toBe(Group::MAX_TITLE_LENGTH)
        ->and(Link::where('user_id', $user->id)->count())->toBe(1);
});
