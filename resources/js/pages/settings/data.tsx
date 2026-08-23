import { Transition } from '@headlessui/react';
import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import ImportController from '@/actions/App/Http/Controllers/ImportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { exportMethod } from '@/routes';
import { edit as editData } from '@/routes/data';
import type { BreadcrumbItem } from '@/types';

type ExportFormat = 'json' | 'html';

interface Props {
    csrfToken: string;
    counts: {
        links: number;
        archivedLinks: number;
        groups: number;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Data',
        href: editData().url,
    },
];

/**
 * Which importer to run is decided by the file the user picked, rather than by
 * asking them to describe a file they are already holding.
 */
function sourceForFile(name: string): ExportFormat | null {
    const lowered = name.toLowerCase();

    if (lowered.endsWith('.json')) {
        return 'json';
    }

    if (lowered.endsWith('.html') || lowered.endsWith('.htm')) {
        return 'html';
    }

    return null;
}

export default function Data({ csrfToken, counts }: Props) {
    const [format, setFormat] = useState<ExportFormat>('json');
    const [importSource, setImportSource] = useState<ExportFormat | null>(null);
    const [fileName, setFileName] = useState<string | null>(null);

    const total = counts.links + counts.archivedLinks;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Data" />

            <h1 className="sr-only">Data</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Export"
                        description="Download everything you have saved. There is no limit and no lock-in."
                    />

                    <p className="text-sm text-muted-foreground">
                        {total === 0
                            ? 'You have nothing saved yet.'
                            : `${total} ${total === 1 ? 'link' : 'links'}${
                                  counts.archivedLinks > 0
                                      ? ` (${counts.archivedLinks} archived)`
                                      : ''
                              } and ${counts.groups} ${
                                  counts.groups === 1
                                      ? 'collection'
                                      : 'collections'
                              }.`}
                    </p>

                    {/*
                     * A plain form, not an Inertia one: the response is a file,
                     * and Inertia's router would swallow it.
                     */}
                    <form
                        method="post"
                        action={exportMethod.url()}
                        className="space-y-4"
                    >
                        <input type="hidden" name="_token" value={csrfToken} />

                        <fieldset className="space-y-3">
                            <legend className="mb-3 text-sm font-medium">
                                Format
                            </legend>

                            <FormatChoice
                                value="json"
                                checked={format === 'json'}
                                onChange={setFormat}
                                title="JSON"
                                description="Everything: notes, tags, ratings, read state, archived links, collections and their rules. Import this back to restore a library."
                            />

                            <FormatChoice
                                value="html"
                                checked={format === 'html'}
                                onChange={setFormat}
                                title="HTML bookmarks"
                                description="The standard bookmarks file every browser reads. Collections become folders and tags travel with each link."
                            />
                        </fieldset>

                        <Button type="submit" disabled={total === 0}>
                            Download export
                        </Button>
                    </form>
                </div>

                <Separator />

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Import"
                        description="Bring in a Linkerlee export, or a bookmarks file from your browser."
                    />

                    <Form
                        {...ImportController.import.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="space-y-4"
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="importFile">File</Label>
                                    <Input
                                        id="importFile"
                                        name="importFile"
                                        type="file"
                                        accept=".json,.html,.htm"
                                        required
                                        onChange={(event) => {
                                            const file =
                                                event.target.files?.[0] ?? null;

                                            setFileName(file?.name ?? null);
                                            setImportSource(
                                                file
                                                    ? sourceForFile(file.name)
                                                    : null,
                                            );
                                        }}
                                    />
                                    {fileName && importSource === null && (
                                        <p className="text-sm text-muted-foreground">
                                            Choose a .json or .html file.
                                        </p>
                                    )}
                                    <InputError message={errors.importFile} />
                                </div>

                                <input
                                    type="hidden"
                                    name="importSource"
                                    value={importSource ?? ''}
                                />
                                <InputError message={errors.importSource} />

                                <fieldset className="space-y-3">
                                    <legend className="mb-3 text-sm font-medium">
                                        What to bring in
                                    </legend>

                                    <div className="flex items-center space-x-3">
                                        <Checkbox
                                            id="import-links"
                                            name="importOptions[]"
                                            value="links"
                                            defaultChecked
                                        />
                                        <Label htmlFor="import-links">
                                            Links and their tags
                                        </Label>
                                    </div>

                                    <div className="flex items-center space-x-3">
                                        <Checkbox
                                            id="import-groups"
                                            name="importOptions[]"
                                            value="groups"
                                            defaultChecked
                                        />
                                        <Label htmlFor="import-groups">
                                            Collections
                                        </Label>
                                    </div>

                                    <InputError
                                        message={errors.importOptions}
                                    />
                                </fieldset>

                                <p className="text-sm text-muted-foreground">
                                    Links you already have are left as they are,
                                    matched by their address.
                                </p>

                                <div className="flex items-center gap-4">
                                    <Button
                                        disabled={
                                            processing || importSource === null
                                        }
                                    >
                                        Import
                                    </Button>
                                    <Transition
                                        show={recentlySuccessful}
                                        enter="transition ease-in-out"
                                        enterFrom="opacity-0"
                                        leave="transition ease-in-out"
                                        leaveTo="opacity-0"
                                    >
                                        <p className="text-sm text-neutral-600">
                                            Imported
                                        </p>
                                    </Transition>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function FormatChoice({
    value,
    checked,
    onChange,
    title,
    description,
}: {
    value: ExportFormat;
    checked: boolean;
    onChange: (value: ExportFormat) => void;
    title: string;
    description: string;
}) {
    return (
        <label
            htmlFor={`format-${value}`}
            className="flex cursor-pointer gap-3 rounded-md border p-4 has-[:checked]:border-primary"
        >
            <input
                id={`format-${value}`}
                type="radio"
                name="exportFormat"
                value={value}
                checked={checked}
                onChange={() => onChange(value)}
                className="mt-1 size-4 shrink-0 accent-primary"
            />
            <span className="space-y-1">
                <span className="block text-sm font-medium">{title}</span>
                <span className="block text-sm text-muted-foreground">
                    {description}
                </span>
            </span>
        </label>
    );
}
