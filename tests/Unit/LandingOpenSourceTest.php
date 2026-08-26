<?php

/**
 * The pages here are client-rendered, so these guard the open-source messaging,
 * the repository links and the extension placement at the source level.
 */
function landingSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/'.$relativePath);
}

test('the repository constants point at the public github repositories', function () {
    expect(landingSource('js/lib/repositories.ts'))
        ->toContain("'https://github.com/linkerlee-app/linkerlee'")
        ->toContain("'https://github.com/linkerlee-app/linkerlee-browser-extension'")
        ->toContain('LICENSE');
});

test('both extension stores carry a real listing link', function () {
    expect(landingSource('js/lib/extensions.ts'))
        ->toContain("'https://addons.mozilla.org/en-US/firefox/addon/linkerlee-bookmarker/'")
        ->toContain("'https://chromewebstore.google.com/detail/linkerlee-bookmarker/mahiooindjjfeahbdmndhhkhigaopaek'")
        ->toContain('href: FIREFOX_ADDON_URL')
        ->toContain('href: CHROME_WEB_STORE_URL');
});

/**
 * strpos() returns false for a missing needle, and false is less than any
 * positive int in PHP — so the positions have to be proven present before they
 * can be compared, or deleting a section would leave this passing.
 */
function landingSectionPosition(string $welcome, string $component): int
{
    $position = strpos($welcome, '<'.$component);

    expect($position)->toBeInt("{$component} is not rendered by the welcome page");

    return $position;
}

test('the extensions section comes before every other section but the hero', function () {
    $welcome = landingSource('js/pages/welcome.tsx');
    $extensions = landingSectionPosition($welcome, 'LandingExtensions');

    foreach ([
        'LandingFeatures',
        'LandingPreview',
        'LandingPricing',
        'LandingOpenSource',
        'LandingFAQ',
    ] as $component) {
        expect($extensions)->toBeLessThan(
            landingSectionPosition($welcome, $component)
        );
    }

    expect($extensions)->toBeGreaterThan(
        landingSectionPosition($welcome, 'LandingHero')
    );
});

/**
 * Source-text assertions cannot see styling, so this guards the structure the
 * nav and footer depend on — the anchor id, and a link per store driven by the
 * shared list — not how prominent the section looks.
 */
test('the extensions section keeps its anchor and links every store', function () {
    expect(landingSource('js/components/landing/landing-extensions.tsx'))
        ->toContain('id="extensions"')
        ->toContain('EXTENSION_STORES.map')
        ->toContain('href={store.href}')
        ->toContain('Install for {store.shortName}');
});

test('the dashboard promotes the extension, dismissibly', function () {
    expect(landingSource('js/pages/dashboard.tsx'))
        ->toContain('<ExtensionPromoCard />');

    expect(landingSource('js/components/extension-promo-card.tsx'))
        ->toContain('EXTENSION_STORES.filter(isPublished)')
        ->toContain('href={store.href}')
        ->toContain("'linkerlee.extension-promo.dismissed'");
});

test('the welcome page renders the open source section', function () {
    expect(landingSource('js/pages/welcome.tsx'))
        ->toContain('LandingOpenSource')
        ->toContain('open-source bookmarking tool');
});

test('the open source section links both repositories', function () {
    expect(landingSource('js/components/landing/landing-open-source.tsx'))
        ->toContain('id="open-source"')
        ->toContain('Linkerlee is open source.')
        ->toContain('APP_REPOSITORY_URL')
        ->toContain('EXTENSION_REPOSITORY_URL');
});

test('the nav points people at the source', function () {
    expect(landingSource('js/components/landing/landing-nav.tsx'))
        ->toContain('APP_REPOSITORY_URL')
        ->toContain('GithubMark');
});

test('the footer links both repositories and the licence', function () {
    expect(landingSource('js/components/landing/landing-footer.tsx'))
        ->toContain('APP_REPOSITORY_URL')
        ->toContain('EXTENSION_REPOSITORY_URL')
        ->toContain('LICENSE_URL');
});

test('the faq answers whether linkerlee is open source', function () {
    expect(landingSource('js/components/landing/landing-faq.tsx'))
        ->toContain('Is Linkerlee open source?')
        ->toContain('APP_REPOSITORY_URL')
        ->toContain('EXTENSION_REPOSITORY_URL');
});
