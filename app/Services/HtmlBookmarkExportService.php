<?php

namespace App\Services;

class HtmlBookmarkExportService
{
    /**
     * Render an export as a Netscape bookmark file.
     *
     * This is the interchange format every browser reads, so it is shaped for
     * them rather than for us: collections become folders, tags ride along in
     * the `TAGS` attribute that Firefox and most bookmark managers understand,
     * and descriptions sit in the `<DD>` the format provides for them.
     *
     * A bookmark file is a tree, so a link filed in several collections is
     * written under the first of them. That is the one place this format cannot
     * hold everything; the JSON export is the lossless one.
     *
     * @param  array<string, mixed>  $export
     */
    public function createHtmlExport(array $export): string
    {
        $links = $export['links'] ?? [];
        $groups = $export['groups'] ?? [];

        $childGroups = [];

        foreach ($groups as $group) {
            $childGroups[$group['parent_id'] ?? 0][] = $group;
        }

        $linksByGroup = [];

        foreach ($links as $link) {
            $groupId = $this->firstGroupId($link, $groups);

            $linksByGroup[$groupId ?? 0][] = $link;
        }

        return "<!DOCTYPE NETSCAPE-Bookmark-file-1>\n"
            ."<!-- This is an automatically generated file.\n"
            ."     It will be read and overwritten.\n"
            ."     DO NOT EDIT! -->\n"
            ."<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=UTF-8\">\n"
            ."<TITLE>Bookmarks</TITLE>\n"
            ."<H1>Bookmarks</H1>\n"
            ."<DL><p>\n"
            .$this->renderLevel($childGroups, $linksByGroup, 0, 1)
            .'</DL>';
    }

    /**
     * Render one folder's contents: the collections nested inside it, then the
     * links filed directly in it.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $childGroups
     * @param  array<int, array<int, array<string, mixed>>>  $linksByGroup
     */
    private function renderLevel(array $childGroups, array $linksByGroup, int $parentId, int $depth): string
    {
        $indent = str_repeat('    ', $depth);
        $html = '';

        foreach ($childGroups[$parentId] ?? [] as $group) {
            $html .= "{$indent}<DT><H3>".$this->escape($group['title'])."</H3>\n";
            $html .= "{$indent}<DL><p>\n";
            $html .= $this->renderLevel($childGroups, $linksByGroup, $group['id'], $depth + 1);
            $html .= "{$indent}</DL><p>\n";
        }

        foreach ($linksByGroup[$parentId] ?? [] as $link) {
            $html .= $this->renderLink($link, $indent);
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private function renderLink(array $link, string $indent): string
    {
        $title = $this->escape($link['title'] ?? $link['link']);
        $attributes = 'HREF="'.$this->escape($link['link']).'"';

        if ($addDate = $this->timestamp($link['created_at'] ?? null)) {
            $attributes .= " ADD_DATE=\"{$addDate}\"";
        }

        if ($lastModified = $this->timestamp($link['updated_at'] ?? null)) {
            $attributes .= " LAST_MODIFIED=\"{$lastModified}\"";
        }

        if (! empty($link['tags']) && is_array($link['tags'])) {
            $attributes .= ' TAGS="'.$this->escape(implode(',', $link['tags'])).'"';
        }

        $html = "{$indent}<DT><A {$attributes}>{$title}</A>\n";

        if (! empty($link['description'])) {
            $html .= "{$indent}<DD>".$this->escape($link['description'])."\n";
        }

        return $html;
    }

    /**
     * The collection a link is written under, ignoring any that the export does
     * not actually describe.
     *
     * @param  array<string, mixed>  $link
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function firstGroupId(array $link, array $groups): ?int
    {
        $groupIds = $link['group_ids'] ?? [];

        if (! is_array($groupIds) || $groupIds === []) {
            return null;
        }

        $knownIds = array_column($groups, 'id');

        foreach ($groupIds as $groupId) {
            if (in_array($groupId, $knownIds)) {
                return (int) $groupId;
            }
        }

        return null;
    }

    private function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtotime($value) ?: null;
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
