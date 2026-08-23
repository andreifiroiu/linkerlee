<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

class HtmlBookmarkImportService
{
    /**
     * Where a folder's assigned id is parked on its own `<DL>` node while the
     * document is being read. Stripped from nothing — the parsed document is
     * discarded once the data is out.
     */
    private const GROUP_ID_ATTRIBUTE = 'data-linkerlee-group-id';

    /**
     * Read a Netscape bookmark file into the shape `ImportService` expects.
     *
     * Browsers write folders as an `<H3>` heading followed by a nested `<DL>`,
     * and carry tags in a `TAGS` attribute. Both are recovered here, so a
     * bookmarks file arrives with its structure rather than as a flat list.
     *
     * @return array<string, mixed>
     */
    public function extractData(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        /** Folders are read first: reading them is what marks the lists a link then looks up. */
        $groups = $this->extractGroups($xpath);

        return [
            'version' => ExportService::FORMAT_VERSION,
            'links' => $this->extractLinks($xpath),
            'groups' => $groups,
        ];
    }

    /**
     * Turn every titled `<DL>` into a collection.
     *
     * A folder's heading is the nearest `<H3>` before its list, and its parent
     * is the list it sits inside. The outermost list is the file itself and has
     * no heading, so it becomes no collection at all.
     *
     * The id is stamped onto the node rather than kept in a map beside it: PHP
     * hands out a fresh wrapper object every time the same underlying node is
     * reached, so the identity of one `DOMElement` cannot be compared with the
     * identity of another. The document itself is the only reliable place to
     * write this down, and the document is ours alone.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractGroups(DOMXPath $xpath): array
    {
        $groups = [];
        $nextId = 1;

        foreach ($xpath->query('//dl') as $list) {
            $heading = $xpath->query('preceding::h3[1]', $list)->item(0);

            if ($heading === null || trim($heading->textContent) === '') {
                continue;
            }

            $parentList = $xpath->query('ancestor::dl[1]', $list)->item(0);
            $parentId = $parentList?->getAttribute(self::GROUP_ID_ATTRIBUTE);

            $list->setAttribute(self::GROUP_ID_ATTRIBUTE, (string) $nextId);

            $groups[] = [
                'id' => $nextId,
                'parent_id' => $parentId === null || $parentId === '' ? null : (int) $parentId,
                'title' => trim($heading->textContent),
            ];

            $nextId++;
        }

        return $groups;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractLinks(DOMXPath $xpath): array
    {
        $links = [];

        foreach ($xpath->query('//a') as $anchor) {
            $href = $anchor->getAttribute('href');

            if (empty($href) || ! filter_var($href, FILTER_VALIDATE_URL)) {
                continue;
            }

            $list = $xpath->query('ancestor::dl[1]', $anchor)->item(0);
            $groupId = $list?->getAttribute(self::GROUP_ID_ATTRIBUTE);
            $groupId = $groupId === null || $groupId === '' ? null : (int) $groupId;

            $links[] = [
                'title' => trim($anchor->textContent) ?: null,
                'link' => $href,
                'tags' => $this->tags($anchor),
                'description' => $this->description($xpath, $anchor),
                'group_ids' => $groupId === null ? [] : [$groupId],
                'created_at' => $this->date($anchor->getAttribute('add_date')),
                'updated_at' => $this->date($anchor->getAttribute('last_modified')),
            ];
        }

        return $links;
    }

    /**
     * @return array<int, string>
     */
    private function tags(DOMElement $anchor): array
    {
        $tags = $anchor->getAttribute('tags');

        if (trim($tags) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $tags))));
    }

    /**
     * The `<DD>` note a bookmark file puts after a link.
     *
     * A `<DD>` belongs to the link it follows, so it counts only when this
     * anchor is the closest one before it.
     */
    private function description(DOMXPath $xpath, DOMElement $anchor): ?string
    {
        $note = $xpath->query('following::dd[1]', $anchor)->item(0);

        if ($note === null) {
            return null;
        }

        $owner = $xpath->query('preceding::a[1]', $note)->item(0);

        if ($owner === null || ! $owner->isSameNode($anchor)) {
            return null;
        }

        return trim($note->textContent) ?: null;
    }

    /**
     * Bookmark files stamp dates as unix seconds.
     */
    private function date(string $value): ?string
    {
        if (! ctype_digit(trim($value)) || (int) $value <= 0) {
            return null;
        }

        return date(DATE_ATOM, (int) $value);
    }
}
