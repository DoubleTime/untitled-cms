import { PropsWithChildren, ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import {
    SidebarInset,
    SidebarProvider,
    SidebarTrigger,
} from '@/Components/ui/sidebar';
import { AppSidebar } from '@/Components/app-sidebar';
import { Separator } from '@/Components/ui/separator';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/Components/ui/breadcrumb';
import { ModeToggle } from '@/Components/mode-toggle';
import { UIProvider } from '@/Contexts/UIContext';
import { GlobalCommandPalette } from '@/Components/GlobalCommandPalette';
import VaultPicker from '@/Components/Vault/VaultPicker';
import { useState, useEffect } from 'react';

export default function AuthenticatedLayout({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const { appName } = usePage().props;

    const [vaultOpen, setVaultOpen] = useState(false);
    const [vaultConfig, setVaultConfig] = useState<{
        mode: 'single' | 'multiple';
        allowedTypes: 'image' | 'document' | 'all';
        onSelect: (files: any[]) => void;
    }>({
        mode: 'single',
        allowedTypes: 'all',
        onSelect: () => { },
    });

    useEffect(() => {
        const handleOpenVault = (event: CustomEvent) => {
            const { mode = 'single', type = 'all', onSelect } = event.detail;
            setVaultConfig({
                mode,
                allowedTypes: type === 'image' ? 'image' : 'all', // Map simple types
                onSelect: onSelect || (() => { }),
            });
            setVaultOpen(true);
        };

        window.addEventListener('open-vault-picker', handleOpenVault as EventListener);
        return () => window.removeEventListener('open-vault-picker', handleOpenVault as EventListener);
    }, []);

    return (
        <UIProvider>
            <SidebarProvider defaultOpen={true}>
                <AppSidebar />
                <GlobalCommandPalette />
                <SidebarInset className="min-w-0 overflow-hidden">
                    <header className="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 border-b bg-background">
                        <div className="flex items-center gap-2 px-4">
                            <SidebarTrigger className="-ml-1" />
                            <Separator orientation="vertical" className="mr-2 h-4" />
                            <Breadcrumb>
                                <BreadcrumbList>
                                    <BreadcrumbItem className="hidden md:block">
                                        <BreadcrumbLink href="#">{appName}</BreadcrumbLink>
                                    </BreadcrumbItem>
                                    <BreadcrumbSeparator className="hidden md:block" />
                                    <BreadcrumbItem>
                                        <BreadcrumbPage>{header || 'Dashboard'}</BreadcrumbPage>
                                    </BreadcrumbItem>
                                </BreadcrumbList>
                            </Breadcrumb>
                        </div>
                        <div className="ml-auto px-4">
                            <ModeToggle />
                        </div>
                    </header>
                    <main className="flex flex-1 flex-col gap-4 p-4 pt-4 min-w-0 overflow-hidden">
                        {children}
                    </main>
                </SidebarInset>

                <VaultPicker
                    open={vaultOpen}
                    onOpenChange={setVaultOpen}
                    mode={vaultConfig.mode}
                    allowedTypes={vaultConfig.allowedTypes}
                    onSelect={vaultConfig.onSelect}
                />
            </SidebarProvider>
        </UIProvider>
    );
}
