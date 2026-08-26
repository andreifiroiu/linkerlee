import { Link } from '@inertiajs/react';
import { Puzzle, X } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import { EXTENSION_STORES, type ExtensionStore } from '@/lib/extensions';
import { index as apiTokensIndex } from '@/routes/api-tokens';

/**
 * Dismissed per browser rather than per user: the browser it is dismissed in is
 * the one the extension was installed in, and the web app cannot detect an
 * installed extension to hide the card by itself.
 */
const DISMISSED_KEY = 'linkerlee.extension-promo.dismissed';

const listeners = new Set<() => void>();

/**
 * Cached so `getSnapshot` returns a stable value rather than hitting storage on
 * every render. Null means "not read yet"; the server renders that, since it
 * cannot know what this browser stored.
 */
let dismissed: boolean | null = null;

function readDismissed(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return localStorage.getItem(DISMISSED_KEY) === '1';
    } catch (error) {
        console.warn(
            'Could not read the extension promo dismissal from storage',
            error,
        );

        return false;
    }
}

function notify(): void {
    listeners.forEach((listener) => listener());
}

const subscribe = (onStoreChange: () => void): (() => void) => {
    listeners.add(onStoreChange);

    /**
     * Another tab dismissing the card writes the key; drop the cache so the
     * next snapshot re-reads it and this tab hides the card too.
     */
    const onStorage = (event: StorageEvent): void => {
        if (event.key === DISMISSED_KEY || event.key === null) {
            dismissed = null;
            notify();
        }
    };

    window.addEventListener('storage', onStorage);

    return () => {
        listeners.delete(onStoreChange);
        window.removeEventListener('storage', onStorage);
    };
};

const getSnapshot = (): boolean | null => {
    if (dismissed === null) {
        dismissed = readDismissed();
    }

    return dismissed;
};

const getServerSnapshot = (): boolean | null => null;

function dismiss(): void {
    dismissed = true;

    try {
        localStorage.setItem(DISMISSED_KEY, '1');
    } catch (error) {
        console.warn(
            'Could not persist the extension promo dismissal; this browser is blocking site storage, so the card will return on the next visit',
            error,
        );
    }

    notify();
}

const isPublished = (
    store: ExtensionStore,
): store is ExtensionStore & { href: string } => store.href !== null;

export function ExtensionPromoCard() {
    const isDismissed = useSyncExternalStore(
        subscribe,
        getSnapshot,
        getServerSnapshot,
    );

    const publishedStores = EXTENSION_STORES.filter(isPublished);

    if (isDismissed !== false || publishedStores.length === 0) {
        return null;
    }

    return (
        <div className="relative rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <Button
                variant="ghost"
                size="icon"
                className="absolute top-2 right-2 size-7 text-muted-foreground"
                onClick={dismiss}
            >
                <X className="size-4" />
                <span className="sr-only">Hide the browser extension card</span>
            </Button>

            <div className="flex items-start gap-3 pr-8">
                <Puzzle className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <div>
                    <h2 className="text-sm font-medium">
                        Save tabs without leaving them
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        The Linkerlee Bookmarker saves the page you are on in
                        one click, with tags suggested from the page itself.
                        Connect it with an{' '}
                        <Link
                            href={apiTokensIndex()}
                            className="underline underline-offset-4 hover:text-foreground"
                        >
                            API token
                        </Link>{' '}
                        from your settings.
                    </p>
                </div>
            </div>

            <div className="mt-4 flex flex-wrap gap-2 sm:pl-8">
                {publishedStores.map((store) => (
                    <Button key={store.id} asChild size="sm" variant="outline">
                        <a href={store.href} target="_blank" rel="noreferrer">
                            Install for {store.shortName}
                        </a>
                    </Button>
                ))}
            </div>
        </div>
    );
}
