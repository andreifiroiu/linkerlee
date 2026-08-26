import { Puzzle } from 'lucide-react';
import { GithubMark } from '@/components/landing/github-mark';
import { EXTENSION_STORES } from '@/lib/extensions';
import { EXTENSION_REPOSITORY_URL } from '@/lib/repositories';

export function LandingExtensions() {
    return (
        <section
            id="extensions"
            className="bg-[#fff8ec] py-20 dark:bg-[#0a0a0a]"
        >
            <div className="mx-auto w-full max-w-6xl px-6">
                <div className="relative overflow-hidden rounded-2xl border border-[#fba115]/35 bg-white px-6 py-12 shadow-[0_24px_60px_-24px_rgba(251,161,21,0.45)] sm:px-12 dark:border-[#fba115]/25 dark:bg-[#0f0f0f]">
                    {/* Amber wash behind the header, echoing the hero's glow. */}
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-x-0 top-0 h-64 bg-[radial-gradient(ellipse_at_top,#ffd49455,transparent_70%)] dark:bg-[radial-gradient(ellipse_at_top,#fba1152e,transparent_70%)]"
                    />

                    <div className="relative mx-auto max-w-2xl text-center">
                        <span className="inline-flex items-center gap-2 rounded-full border border-[#fba115]/40 bg-[#fff8ec] px-3 py-1 text-xs font-medium text-[#c97208] dark:bg-[#fba115]/10 dark:text-[#ffc266]">
                            <Puzzle className="size-3.5" />
                            Browser extension
                        </span>
                        <h2 className="mt-6 text-3xl font-semibold tracking-tight text-balance text-[#1a1410] sm:text-4xl dark:text-white">
                            Save the tab you&apos;re on, in one click.
                        </h2>
                        <p className="mt-4 text-pretty text-[#1a1410]/60 dark:text-white/60">
                            The Linkerlee Bookmarker pre-fills the URL and
                            title, suggests tags that match the page, and tells
                            you when you&apos;ve already saved something.
                            Connect it once with an API token from your
                            settings.
                        </p>
                    </div>

                    <div className="relative mt-10 grid gap-4 md:grid-cols-2">
                        {EXTENSION_STORES.map((store) => (
                            <div
                                key={store.id}
                                className="flex flex-col rounded-2xl border border-[#1a141015] bg-[#fff8ec] p-7 dark:border-white/10 dark:bg-[#151515]"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <h3 className="text-base font-semibold text-[#1a1410] dark:text-white">
                                        {store.name}
                                    </h3>
                                    {store.href === null && (
                                        <span className="rounded-full bg-[#1a141010] px-2.5 py-1 text-[11px] font-medium text-[#1a1410]/50 dark:bg-white/10 dark:text-white/50">
                                            Coming soon
                                        </span>
                                    )}
                                </div>
                                <p className="mt-2 text-sm text-[#1a1410]/60 dark:text-white/60">
                                    {store.browsers}
                                </p>
                                <p className="mt-4 text-sm leading-relaxed text-[#1a1410]/50 dark:text-white/50">
                                    {store.note}
                                </p>
                                {store.href !== null && (
                                    <div className="mt-auto pt-6">
                                        <a
                                            href={store.href}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex h-11 w-full items-center justify-center rounded-md bg-[#fba115] px-6 text-sm font-semibold text-[#1a1410] shadow-[0_8px_24px_-8px_rgba(251,161,21,0.6)] transition hover:bg-[#e8890a] hover:text-white md:w-auto"
                                        >
                                            Install for {store.shortName}
                                        </a>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>

                    <p className="relative mt-8 text-center text-sm text-[#1a1410]/50 dark:text-white/50">
                        The extension is open source too —{' '}
                        <a
                            href={EXTENSION_REPOSITORY_URL}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 text-[#c97208] underline-offset-4 hover:underline dark:text-[#ffc266]"
                        >
                            <GithubMark className="size-3.5" />
                            read the code or build it yourself
                        </a>
                        .
                    </p>
                </div>
            </div>
        </section>
    );
}
