import PublicLayout from '@/Layouts/PublicLayout';
import { Head, Link } from '@inertiajs/react';
import { cn, isExternal } from '@/lib/utils';
import { Card, CardContent, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import {
    Carousel,
    CarouselContent,
    CarouselItem,
    CarouselNext,
    CarouselPrevious,
} from "@/Components/ui/carousel";
import Autoplay from 'embla-carousel-autoplay';
import { useRef } from 'react';

interface Slide {
    image: string;
    url?: string;
    sequence?: number;
    title?: string;
    subtitle?: string;
    caption?: string;
    target?: '_self' | '_blank';
}

interface Banner {
    id: string;
    title: string;
    slides: Slide[];
}

interface Page {
    id: string;
    title: string;
    slug: string;
    seo_description?: string;
    featured_images?: string[];
    published_at: string;
}

interface Props {
    banners: Banner[];
    recentPages: Page[];
}

export default function Home({ banners, recentPages }: Props) {
    const plugin = useRef(
        Autoplay({ delay: 5000, stopOnInteraction: true })
    );

    const allSlides = banners.flatMap(b => b.slides || []).sort((a, b) => (a.sequence || 0) - (b.sequence || 0));

    // Removed local isExternal in favor of @/lib/utils

    return (
        <PublicLayout>
            <Head title="Home" />

            {/* Hero / Banners Section */}
            {allSlides.length > 0 ? (
                <div className="w-full bg-background border-b relative">
                    <Carousel
                        plugins={[plugin.current]}
                        className="w-full"
                        onMouseEnter={plugin.current.stop}
                        onMouseLeave={plugin.current.reset}
                        opts={{ loop: true }}
                    >
                        <CarouselContent>
                            {allSlides.map((slide, index) => (
                                <CarouselItem key={`slide-${slide.sequence ?? index}`}>
                                    <div className="relative h-[50vh] min-h-[400px] w-full overflow-hidden">
                                        <div className="absolute inset-0 bg-foreground/20 z-10" /> {/* Dim overlay */}
                                        <img
                                            src={slide.image}
                                            alt={slide.title || "Banner slide"}
                                            className="absolute inset-0 w-full h-full object-cover"
                                        />
                                        <div className="absolute inset-0 z-20 flex items-center justify-center">
                                            <div className="container max-w-5xl px-4 text-center">
                                                {slide.title && (
                                                    <h1 className="text-4xl md:text-6xl font-bold tracking-tight text-white drop-shadow-md mb-4 animate-in fade-in slide-in-from-bottom-4 duration-700">
                                                        {slide.title}
                                                    </h1>
                                                )}
                                                {slide.subtitle && (
                                                    <p className="text-xl md:text-2xl text-gray-200 drop-shadow-sm mb-6 max-w-2xl mx-auto animate-in fade-in slide-in-from-bottom-5 duration-700 delay-150 fill-mode-backwards">
                                                        {slide.subtitle}
                                                    </p>
                                                )}
                                                {slide.caption && (
                                                    <p className="text-sm text-gray-300 drop-shadow-sm mb-8 uppercase tracking-widest animate-in fade-in slide-in-from-bottom-6 duration-700 delay-300 fill-mode-backwards">
                                                        {slide.caption}
                                                    </p>
                                                )}
                                                {slide.url && (
                                                    <Button asChild size="lg" className="animate-in fade-in zoom-in-95 duration-700 delay-500 fill-mode-backwards">
                                                        {isExternal(slide.url) ? (
                                                            <a href={slide.url} target={slide.target || '_self'} rel={slide.target === '_blank' ? 'noopener noreferrer' : undefined}>Learn More</a>
                                                        ) : (
                                                            <Link href={slide.url}>Learn More</Link>
                                                        )}
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </CarouselItem>
                            ))}
                        </CarouselContent>
                        {allSlides.length > 1 && (
                            <>
                                <CarouselPrevious className="left-4 bg-background/50 hover:bg-background/90 border-none text-foreground" />
                                <CarouselNext className="right-4 bg-background/50 hover:bg-background/90 border-none text-foreground" />
                            </>
                        )}
                    </Carousel>
                </div>
            ) : (
                <section className="relative w-full overflow-hidden border-b">
                    <div className="absolute inset-0 bg-linear-to-br from-primary/10 via-background to-muted/30" />
                    <div className="relative container max-w-5xl mx-auto px-4 py-32 text-center">
                        <h1 className="text-5xl md:text-7xl font-bold tracking-tight mb-6">
                            Content that <span className="text-primary">speaks</span> for itself.
                        </h1>
                        <p className="text-lg md:text-xl text-muted-foreground max-w-2xl mx-auto mb-10">
                            An AI-native CMS built on Laravel, React, and PostgreSQL — ready for teams, agents, and the open web.
                        </p>
                        <div className="flex gap-4 justify-center">
                            <Button asChild size="lg" className="h-12 px-8">
                                <Link href="/getting-started">Get Started</Link>
                            </Button>
                            <Button asChild size="lg" variant="outline" className="h-12 px-8">
                                <Link href={route('login')}>Sign In</Link>
                            </Button>
                        </div>
                    </div>
                </section>
            )}

            {/* Features Strip */}
            <div className="border-b bg-muted/20">
                <div className="container max-w-7xl mx-auto px-4 py-6 flex flex-wrap justify-center gap-6 text-sm text-muted-foreground">
                    <span className="flex items-center gap-2"><span className="h-1.5 w-1.5 rounded-full bg-primary inline-block" />Laravel 13 + PostgreSQL</span>
                    <span className="flex items-center gap-2"><span className="h-1.5 w-1.5 rounded-full bg-primary inline-block" />React + Inertia.js SPA</span>
                    <span className="flex items-center gap-2"><span className="h-1.5 w-1.5 rounded-full bg-primary inline-block" />Multi-Provider AI Hub</span>
                    <span className="flex items-center gap-2"><span className="h-1.5 w-1.5 rounded-full bg-primary inline-block" />Role-Based Access Control</span>
                    <span className="flex items-center gap-2"><span className="h-1.5 w-1.5 rounded-full bg-primary inline-block" />Markdown for AI Agents</span>
                </div>
            </div>

            {/* From the Blog Section */}
            <div className="container max-w-7xl mx-auto py-24 px-4 sm:px-6 lg:px-8">
                <div className="flex flex-col md:flex-row justify-between items-end mb-12 border-b pb-4">
                    <div>
                        <h2 className="text-3xl font-bold tracking-tight">From the Blog</h2>
                        <p className="text-muted-foreground mt-2">Latest updates and featured content from our team.</p>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    {recentPages.length > 0 ? (
                        recentPages.map((page) => (
                            <Card key={page.id} className="overflow-hidden group hover:shadow-md transition-all duration-300 bg-card/50 backdrop-blur-xs border-muted/50 hover:border-primary/20">
                                <Link href={route('public.page', page.slug)}>
                                    <div className="relative aspect-video overflow-hidden bg-muted">
                                        {page.featured_images && page.featured_images.length > 0 ? (
                                            <img
                                                src={page.featured_images[0]}
                                                alt={page.title}
                                                className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                            />
                                        ) : (
                                            <div className="w-full h-full flex items-center justify-center text-muted-foreground group-hover:scale-105 transition-transform duration-500">
                                                <span className="text-sm uppercase tracking-widest">No Image</span>
                                            </div>
                                        )}
                                    </div>
                                    <CardContent className="p-6">
                                        <div className="flex items-center gap-2 text-xs text-muted-foreground mb-3">
                                            <time dateTime={page.published_at}>
                                                {new Date(page.published_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })}
                                            </time>
                                            <span>•</span>
                                            <span className="capitalize">Article</span>
                                        </div>
                                        <CardTitle className="mb-2 line-clamp-2 group-hover:text-primary transition-colors">
                                            {page.title}
                                        </CardTitle>
                                        <CardDescription className="line-clamp-3">
                                            {page.seo_description || 'Click to read more about this topic.'}
                                        </CardDescription>
                                    </CardContent>
                                </Link>
                            </Card>
                        ))
                    ) : (
                        <div className="col-span-full text-center py-16 text-muted-foreground">
                            <p className="text-lg font-medium mb-2">No articles yet.</p>
                            <p className="text-sm">Start publishing pages in the admin panel to see them here.</p>
                        </div>
                    )}
                </div>
            </div>
        </PublicLayout>
    );
}
