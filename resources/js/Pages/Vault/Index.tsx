import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import VaultUploadDialog from '@/Components/Vault/VaultUploadDialog';
import VaultDialogs from '@/Components/Vault/VaultDialogs';
import VaultFolderInfoPopover from '@/Components/Vault/VaultFolderInfoPopover';
import VaultThumbnail from '@/Components/Vault/VaultThumbnail';
import VaultBreadcrumb from '@/Components/Vault/VaultBreadcrumb';
import VaultFileIcon from '@/Components/Vault/VaultFileIcon';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    ResizableHandle,
    ResizablePanel,
    ResizablePanelGroup,
} from '@/Components/ui/resizable';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '@/Components/ui/context-menu';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import {
    Folder,
    FolderPlus,
    Search,
    Upload,
    Trash2,
    Download,
    Move,
    LayoutGrid,
    List,
    Check,
    Sparkles,
    Loader2,
    Zap,
    Info,
    FileIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { useVaultBrowser } from '@/hooks/useVaultBrowser';
import { VaultFolder } from '@/types/vault';

export default function VaultIndex({
    maxUploadSize = 2,
}: {
    maxUploadSize?: number;
    phpIniPath?: string;
}) {
    const vault = useVaultBrowser();

    const renderFolderTree = (parentId: string | null = null, depth = 0) => {
        const children = vault.folders.filter((f) => f.parent_id === parentId);
        return children.map((folder) => (
            <div key={folder.id}>
                <div
                    className={cn(
                        'flex items-center gap-2 px-2 py-1.5 hover:bg-accent rounded-sm cursor-pointer text-sm',
                        !vault.isTrashView &&
                            vault.currentFolder?.id === folder.id &&
                            'bg-accent text-accent-foreground font-medium',
                    )}
                    style={{ paddingLeft: `${depth * 12 + 8}px` }}
                    onClick={() => vault.openFolder(folder)}
                >
                    <Folder className="h-4 w-4 shrink-0 fill-current text-blue-500/80" />
                    <span className="truncate">{folder.name}</span>
                </div>
                {renderFolderTree(folder.id, depth + 1)}
            </div>
        ));
    };

    const childFolders = vault.folders.filter(
        (f) => f.parent_id === (vault.currentFolder?.id || null),
    );

    return (
        <AuthenticatedLayout header="Media Vault">
            <Head title="Media Vault" />

            <div className="flex flex-col flex-1 min-h-0 w-full max-w-full rounded-xl border bg-card shadow-xs overflow-hidden">
                <div className="flex items-center justify-between p-4 border-b shrink-0">
                    <div className="flex items-center gap-2">
                        <Button
                            onClick={() => vault.setIsCreateFolderOpen(true)}
                            variant="outline"
                            size="sm"
                            className="gap-2"
                        >
                            <FolderPlus className="h-4 w-4" />
                            New Folder
                        </Button>
                        <Button
                            onClick={() => vault.setIsUploadOpen(true)}
                            variant="default"
                            size="sm"
                            className="gap-2"
                        >
                            <Upload className="h-4 w-4" />
                            Upload Files
                        </Button>
                    </div>

                    <div className="relative">
                        <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            type="search"
                            placeholder="Search files..."
                            className="pl-8 w-[200px] lg:w-[300px]"
                            value={vault.search}
                            onChange={(e) => vault.setSearch(e.target.value)}
                        />
                    </div>
                </div>

                <ResizablePanelGroup
                    id="vault-panel-group"
                    orientation="horizontal"
                    className="flex-1 h-full w-full max-w-full"
                >
                    <ResizablePanel
                        id="vault-sidebar"
                        defaultSize="20"
                        minSize="10"
                        maxSize="50"
                        className="bg-muted/10"
                    >
                        <div className="h-full min-w-0 flex flex-col">
                            <ScrollArea className="flex-1">
                                <div className="p-2 space-y-1">
                                    <div
                                        className={cn(
                                            'flex items-center gap-2 px-2 py-1.5 hover:bg-accent rounded-sm cursor-pointer text-sm',
                                            !vault.isTrashView &&
                                                vault.currentFolder === null &&
                                                'bg-accent text-accent-foreground font-medium',
                                        )}
                                        onClick={() => vault.openRoot()}
                                    >
                                        <Folder className="h-4 w-4 shrink-0 fill-current text-blue-500/80" />
                                        <span>All Files</span>
                                    </div>
                                    <div className="pt-2">{renderFolderTree(null, 0)}</div>
                                </div>
                            </ScrollArea>
                            <div className="p-2 border-t mt-auto shrink-0 bg-muted/10">
                                <div
                                    className={cn(
                                        'flex items-center gap-2 px-2 py-1.5 hover:bg-accent rounded-sm cursor-pointer text-sm',
                                        vault.isTrashView &&
                                            'bg-accent text-accent-foreground font-medium text-red-600',
                                    )}
                                    onClick={() => vault.openTrash()}
                                >
                                    <Trash2 className="h-4 w-4 shrink-0 fill-current text-red-500/80" />
                                    <span>Trash</span>
                                </div>
                            </div>
                        </div>
                    </ResizablePanel>

                    <ResizableHandle />

                    <ResizablePanel id="vault-main" defaultSize="55">
                        <div className="flex flex-col h-full min-w-0">
                            <div className="px-4 py-2 border-b bg-muted/20 flex items-center justify-between">
                                <div className="flex-1 flex items-center min-w-0">
                                    {vault.isTrashView ? (
                                        <h2 className="text-sm font-semibold text-red-600 flex items-center gap-2">
                                            <Trash2 className="h-4 w-4" /> Trash
                                        </h2>
                                    ) : (
                                        <VaultBreadcrumb
                                            folder={vault.currentFolder}
                                            ancestors={vault.ancestors}
                                            onNavigate={(id) => {
                                                vault.openFolder(
                                                    vault.folders.find((f) => f.id === id) || null,
                                                );
                                            }}
                                        />
                                    )}
                                </div>
                                <div className="flex items-center gap-1 bg-background border rounded-md p-0.5 ml-4 shrink-0 shadow-xs">
                                    <Button
                                        variant={vault.viewMode === 'grid' ? 'secondary' : 'ghost'}
                                        size="sm"
                                        className="h-7 px-2"
                                        onClick={() => vault.setViewMode('grid')}
                                    >
                                        <LayoutGrid className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant={vault.viewMode === 'list' ? 'secondary' : 'ghost'}
                                        size="sm"
                                        className="h-7 px-2"
                                        onClick={() => vault.setViewMode('list')}
                                    >
                                        <List className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>

                            <ScrollArea className="flex-1 p-4">
                                {vault.loading ? (
                                    <div className="py-20 text-center text-muted-foreground">
                                        Loading...
                                    </div>
                                ) : vault.viewMode === 'grid' ? (
                                    <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-4">
                                        {!vault.isTrashView &&
                                            childFolders.map((folder) => (
                                                <ContextMenu key={folder.id}>
                                                    <ContextMenuTrigger>
                                                        <div
                                                            className="group relative flex flex-col items-center gap-2 p-4 border rounded-lg hover:bg-accent cursor-pointer transition-colors"
                                                            onClick={() => vault.openFolder(folder)}
                                                        >
                                                            <div
                                                                className="absolute top-2 right-2 z-10"
                                                                onClick={(e: React.MouseEvent) =>
                                                                    e.stopPropagation()
                                                                }
                                                            >
                                                                <VaultFolderInfoPopover
                                                                    folder={folder}
                                                                    side="right"
                                                                />
                                                            </div>
                                                            <div className="aspect-square w-full relative overflow-hidden flex items-center justify-center">
                                                                <Folder className="h-16 w-16 fill-blue-100 text-blue-500" />
                                                            </div>
                                                            <span
                                                                className="text-sm font-medium truncate w-full text-center"
                                                                title={folder.name}
                                                            >
                                                                {folder.name}
                                                            </span>
                                                        </div>
                                                    </ContextMenuTrigger>
                                                    <ContextMenuContent>
                                                        <ContextMenuItem
                                                            onClick={() => vault.openFolder(folder)}
                                                        >
                                                            Open
                                                        </ContextMenuItem>
                                                        <ContextMenuItem
                                                            onClick={() =>
                                                                vault.handleDelete(
                                                                    'folder',
                                                                    folder.id,
                                                                )
                                                            }
                                                            className="text-red-600"
                                                        >
                                                            Delete
                                                        </ContextMenuItem>
                                                    </ContextMenuContent>
                                                </ContextMenu>
                                            ))}

                                        {vault.files.map((file) => (
                                            <ContextMenu key={file.id}>
                                                <ContextMenuTrigger>
                                                    <VaultThumbnail
                                                        file={file}
                                                        selected={vault.selectedFiles.some(
                                                            (f) => f.id === file.id,
                                                        )}
                                                        onSelect={(e) =>
                                                            vault.handleSelectFile(e, file)
                                                        }
                                                        onDoubleClick={() =>
                                                            window.open(
                                                                route(
                                                                    'admin.vault.file.serve',
                                                                    file.uuid,
                                                                ),
                                                                '_blank',
                                                            )
                                                        }
                                                    />
                                                </ContextMenuTrigger>
                                                <ContextMenuContent>
                                                    {vault.isTrashView ? (
                                                        <>
                                                            <ContextMenuItem
                                                                onClick={() =>
                                                                    vault.handleRestore(
                                                                        'file',
                                                                        file.uuid,
                                                                    )
                                                                }
                                                                className="text-green-600"
                                                            >
                                                                Restore
                                                            </ContextMenuItem>
                                                            <ContextMenuSeparator />
                                                            <ContextMenuItem
                                                                onClick={() =>
                                                                    vault.handleForceDelete(
                                                                        'file',
                                                                        file.uuid,
                                                                    )
                                                                }
                                                                className="text-red-600"
                                                            >
                                                                <Trash2 className="mr-2 h-4 w-4" />{' '}
                                                                Delete Permanently
                                                            </ContextMenuItem>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <ContextMenuItem
                                                                onClick={() =>
                                                                    window.open(
                                                                        route(
                                                                            'admin.vault.file.serve',
                                                                            file.uuid,
                                                                        ),
                                                                        '_blank',
                                                                    )
                                                                }
                                                            >
                                                                <Download className="mr-2 h-4 w-4" />{' '}
                                                                Download/View
                                                            </ContextMenuItem>
                                                            <ContextMenuSeparator />
                                                            <ContextMenuItem
                                                                onClick={() =>
                                                                    vault.handleDelete(
                                                                        'file',
                                                                        file.uuid,
                                                                    )
                                                                }
                                                                className="text-red-600"
                                                            >
                                                                <Trash2 className="mr-2 h-4 w-4" />{' '}
                                                                Delete
                                                            </ContextMenuItem>
                                                        </>
                                                    )}
                                                </ContextMenuContent>
                                            </ContextMenu>
                                        ))}

                                        {!vault.loading &&
                                            vault.files.length === 0 &&
                                            childFolders.length === 0 && (
                                                <div className="col-span-full py-20 flex flex-col items-center justify-center text-muted-foreground">
                                                    <Folder className="h-12 w-12 mb-4 opacity-10" />
                                                    <p>No items in this folder</p>
                                                </div>
                                            )}
                                    </div>
                                ) : (
                                    <div className="flex flex-col border rounded-md divide-y bg-background shadow-xs">
                                        {!vault.isTrashView &&
                                            childFolders.map((folder: VaultFolder) => (
                                                <ContextMenu key={folder.id}>
                                                    <ContextMenuTrigger>
                                                        <div
                                                            className="flex items-center gap-4 p-3 hover:bg-accent cursor-pointer transition-colors"
                                                            onClick={() => vault.openFolder(folder)}
                                                        >
                                                            <Folder className="h-8 w-8 fill-blue-100 text-blue-500 shrink-0" />
                                                            <span className="text-sm font-medium flex-1 truncate">
                                                                {folder.name}
                                                            </span>
                                                            <div
                                                                className="shrink-0"
                                                                onClick={(e: React.MouseEvent) =>
                                                                    e.stopPropagation()
                                                                }
                                                            >
                                                                <VaultFolderInfoPopover
                                                                    folder={folder}
                                                                    side="left"
                                                                />
                                                            </div>
                                                        </div>
                                                    </ContextMenuTrigger>
                                                    <ContextMenuContent>
                                                        <ContextMenuItem
                                                            onClick={() => vault.openFolder(folder)}
                                                        >
                                                            Open
                                                        </ContextMenuItem>
                                                        <ContextMenuItem
                                                            onClick={() =>
                                                                vault.handleDelete(
                                                                    'folder',
                                                                    folder.id,
                                                                )
                                                            }
                                                            className="text-red-600"
                                                        >
                                                            Delete
                                                        </ContextMenuItem>
                                                    </ContextMenuContent>
                                                </ContextMenu>
                                            ))}

                                        {vault.files.map((file) => (
                                            <ContextMenu key={file.id}>
                                                <ContextMenuTrigger>
                                                    <div
                                                        className={cn(
                                                            'group relative flex items-center gap-4 p-3 hover:bg-accent cursor-pointer transition-colors',
                                                            vault.selectedFiles.some(
                                                                (f) => f.id === file.id,
                                                            ) && 'bg-accent/50',
                                                        )}
                                                        onClick={(e) =>
                                                            vault.handleSelectFile(e, file)
                                                        }
                                                        onDoubleClick={() =>
                                                            window.open(
                                                                route(
                                                                    'admin.vault.file.serve',
                                                                    file.uuid,
                                                                ),
                                                                '_blank',
                                                            )
                                                        }
                                                    >
                                                        <div className="relative h-10 w-10 shrink-0 bg-muted/20 border rounded flex items-center justify-center overflow-hidden">
                                                            {file.mime_type.startsWith('image/') &&
                                                            file.url ? (
                                                                <>
                                                                    <img
                                                                        src={file.url}
                                                                        alt={file.original_name}
                                                                        className="object-cover w-full h-full"
                                                                    />
                                                                    {file.is_optimized &&
                                                                        !file.use_original && (
                                                                            <div className="absolute top-1 right-1 bg-black/60 backdrop-blur-xs rounded p-0.5 shadow-xs">
                                                                                <Zap className="h-3 w-3 text-amber-400 fill-amber-400" />
                                                                            </div>
                                                                        )}
                                                                </>
                                                            ) : (
                                                                <VaultFileIcon
                                                                    mimeType={file.mime_type}
                                                                    className="h-6 w-6 text-muted-foreground"
                                                                />
                                                            )}
                                                            {vault.selectedFiles.some(
                                                                (f) => f.id === file.id,
                                                            ) && (
                                                                <div className="absolute inset-0 bg-primary/20 flex items-center justify-center">
                                                                    <div className="bg-primary text-primary-foreground rounded-full p-0.5 shadow-xs">
                                                                        <Check className="h-4 w-4" />
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="flex-1 min-w-0 flex flex-col justify-center">
                                                            <span className="text-sm font-medium truncate">
                                                                {file.original_name}
                                                            </span>
                                                        </div>
                                                        <div className="w-24 shrink-0 text-xs text-muted-foreground text-right hidden sm:block">
                                                            {(file.size_bytes / 1024).toFixed(1)} KB
                                                        </div>
                                                        <div className="w-32 shrink-0 text-xs text-muted-foreground text-right hidden md:block">
                                                            {new Date(
                                                                file.created_at,
                                                            ).toLocaleDateString()}
                                                        </div>
                                                    </div>
                                                </ContextMenuTrigger>
                                                <ContextMenuContent>
                                                    {vault.isTrashView ? (
                                                        <>
                                                            <ContextMenuItem
                                                                onClick={() =>
                                                                    vault.handleRestore(
                                                                        'file',
                                                                        file.uuid,
                                                                    )
                                                                }
                                                                className="text-green-600"
                                                            >
                                                                Restore
                                                            </ContextMenuItem>
                                                            <ContextMenuSeparator />
                                                            <ContextMenuItem
                                                                onClick={() =>
                                                                    vault.handleForceDelete(
                                                                        'file',
                                                                        file.uuid,
                                                                    )
                                                                }
                                                                className="text-red-600"
                                                            >
                                                                <Trash2 className="mr-2 h-4 w-4" />{' '}
                                                                Delete Permanently
                                                            </ContextMenuItem>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <ContextMenuItem
                                                                onClick={() =>
                                                                    window.open(
                                                                        route(
                                                                            'admin.vault.file.serve',
                                                                            file.uuid,
                                                                        ),
                                                                        '_blank',
                                                                    )
                                                                }
                                                            >
                                                                <Download className="mr-2 h-4 w-4" />{' '}
                                                                Download/View
                                                            </ContextMenuItem>
                                                            <ContextMenuSeparator />
                                                            <ContextMenuItem
                                                                onClick={() =>
                                                                    vault.handleDelete(
                                                                        'file',
                                                                        file.uuid,
                                                                    )
                                                                }
                                                                className="text-red-600"
                                                            >
                                                                <Trash2 className="mr-2 h-4 w-4" />{' '}
                                                                Delete
                                                            </ContextMenuItem>
                                                        </>
                                                    )}
                                                </ContextMenuContent>
                                            </ContextMenu>
                                        ))}

                                        {!vault.loading &&
                                            vault.files.length === 0 &&
                                            childFolders.length === 0 && (
                                                <div className="py-12 flex flex-col items-center justify-center text-muted-foreground">
                                                    <Folder className="h-8 w-8 mb-2 opacity-10" />
                                                    <p className="text-sm">No items in this folder</p>
                                                </div>
                                            )}
                                    </div>
                                )}
                            </ScrollArea>
                        </div>
                    </ResizablePanel>

                    <ResizableHandle />

                    <ResizablePanel
                        id="vault-inspector"
                        defaultSize="25"
                        minSize="15"
                        maxSize="50"
                        className="bg-muted/5"
                    >
                        <ScrollArea className="h-full">
                            {vault.selectedFiles.length > 0 ? (
                                <div className="p-6 space-y-6 text-sm">
                                    {vault.selectedFiles.length === 1 ? (
                                        <>
                                            <div className="aspect-square w-full rounded-lg border bg-background flex items-center justify-center overflow-hidden">
                                                {vault.selectedFiles[0].mime_type.startsWith(
                                                    'image/',
                                                ) ? (
                                                    <img
                                                        src={vault.selectedFiles[0].url}
                                                        alt={vault.selectedFiles[0].original_name}
                                                        className="object-contain w-full h-full"
                                                    />
                                                ) : (
                                                    <VaultFileIcon
                                                        mimeType={vault.selectedFiles[0].mime_type}
                                                        className="h-24 w-24 text-muted-foreground"
                                                    />
                                                )}
                                            </div>

                                            <div className="space-y-1">
                                                <h3 className="font-semibold text-lg wrap-break-word">
                                                    {vault.selectedFiles[0].original_name}
                                                </h3>
                                                <p className="text-sm text-muted-foreground uppercase">
                                                    {vault.selectedFiles[0].extension}
                                                </p>
                                            </div>

                                            {vault.selectedFiles[0].mime_type.startsWith(
                                                'image/',
                                            ) && (
                                                <div className="space-y-2 bg-primary/5 border border-primary/20 rounded-lg p-3">
                                                    <div className="flex items-center gap-2">
                                                        <Sparkles className="h-4 w-4 text-primary" />
                                                        <span className="text-xs font-medium text-primary">
                                                            Alt Text
                                                        </span>
                                                    </div>
                                                    <Textarea
                                                        className="text-xs min-h-[60px] resize-none"
                                                        placeholder="Describe this image for accessibility…"
                                                        value={
                                                            vault.generatedAltText ??
                                                            (vault.selectedFiles[0].alt_text || '')
                                                        }
                                                        onChange={(e) =>
                                                            vault.setGeneratedAltText(e.target.value)
                                                        }
                                                    />
                                                    <div className="flex gap-2">
                                                        <Button
                                                            size="sm"
                                                            className="flex-1 h-7 text-xs"
                                                            disabled={
                                                                !(
                                                                    vault.generatedAltText ??
                                                                    (vault.selectedFiles[0]
                                                                        .alt_text || '')
                                                                ).trim()
                                                            }
                                                            onClick={async () => {
                                                                const currentAlt =
                                                                    vault.generatedAltText ??
                                                                    (vault.selectedFiles[0]
                                                                        .alt_text ||
                                                                        '');
                                                                try {
                                                                    await axios.patch(
                                                                        route(
                                                                            'admin.vault.file.alt_text',
                                                                            vault.selectedFiles[0]
                                                                                .uuid,
                                                                        ),
                                                                        { alt_text: currentAlt },
                                                                    );
                                                                    toast.success('Alt text saved!');
                                                                    vault.refresh(
                                                                        vault.currentFolder?.id ||
                                                                            null,
                                                                    );
                                                                } catch {
                                                                    toast.error(
                                                                        'Failed to save alt text.',
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            Save
                                                        </Button>
                                                    </div>
                                                </div>
                                            )}

                                            <div className="grid gap-4 py-4 border-y text-sm">
                                                <div className="grid grid-cols-2">
                                                    <span className="text-muted-foreground">
                                                        Original Size
                                                    </span>
                                                    <span>
                                                        {(
                                                            vault.selectedFiles[0].size_bytes / 1024
                                                        ).toFixed(1)}{' '}
                                                        KB
                                                    </span>
                                                </div>
                                                {vault.selectedFiles[0].is_optimized &&
                                                    vault.selectedFiles[0].optimized_size && (
                                                        <div className="grid grid-cols-2">
                                                            <span className="text-muted-foreground">
                                                                Optimized (WebP)
                                                            </span>
                                                            <div className="flex items-center gap-2">
                                                                <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                                                    {(
                                                                        vault.selectedFiles[0]
                                                                            .optimized_size / 1024
                                                                    ).toFixed(1)}{' '}
                                                                    KB
                                                                </span>
                                                                <span className="text-[10px] bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300 px-1.5 py-0.5 rounded-sm">
                                                                    -
                                                                    {Math.round(
                                                                        ((vault.selectedFiles[0]
                                                                            .size_bytes -
                                                                            vault.selectedFiles[0]
                                                                                .optimized_size) /
                                                                            vault.selectedFiles[0]
                                                                                .size_bytes) *
                                                                            100,
                                                                    )}
                                                                    %
                                                                </span>
                                                            </div>
                                                        </div>
                                                    )}
                                                <div className="grid grid-cols-2">
                                                    <span className="text-muted-foreground">
                                                        Type
                                                    </span>
                                                    <span
                                                        className="truncate"
                                                        title={vault.selectedFiles[0].mime_type}
                                                    >
                                                        {vault.selectedFiles[0].mime_type}
                                                    </span>
                                                </div>
                                                <div className="grid grid-cols-2">
                                                    <span className="text-muted-foreground">
                                                        Uploaded
                                                    </span>
                                                    <span>
                                                        {new Date(
                                                            vault.selectedFiles[0].created_at,
                                                        ).toLocaleDateString()}
                                                    </span>
                                                </div>
                                                {vault.selectedFiles[0].width && (
                                                    <div className="grid grid-cols-2">
                                                        <span className="text-muted-foreground">
                                                            Dimensions
                                                        </span>
                                                        <span>
                                                            {vault.selectedFiles[0].width} x{' '}
                                                            {vault.selectedFiles[0].height}
                                                        </span>
                                                    </div>
                                                )}
                                                {vault.selectedFiles[0].is_optimized && (
                                                    <div className="pt-4 mt-2 border-t">
                                                        <div className="flex items-center justify-between">
                                                            <div className="space-y-0.5">
                                                                <Label className="text-sm font-medium flex items-center gap-1.5">
                                                                    <Zap className="h-3.5 w-3.5 text-amber-500 fill-amber-500" />
                                                                    Serve Optimized
                                                                </Label>
                                                                <p className="text-xs text-muted-foreground">
                                                                    Load WebP format instead of
                                                                    original
                                                                </p>
                                                            </div>
                                                            <Switch
                                                                checked={
                                                                    !vault.selectedFiles[0]
                                                                        .use_original
                                                                }
                                                                onCheckedChange={async (
                                                                    checked,
                                                                ) => {
                                                                    try {
                                                                        const res = await axios.patch(
                                                                            route(
                                                                                'admin.vault.file.toggle_optimization',
                                                                                vault.selectedFiles[0]
                                                                                    .uuid,
                                                                            ),
                                                                            {
                                                                                use_original:
                                                                                    !checked,
                                                                            },
                                                                        );
                                                                        const updatedFiles = [
                                                                            ...vault.files,
                                                                        ];
                                                                        const fileIdx =
                                                                            updatedFiles.findIndex(
                                                                                (f) =>
                                                                                    f.id ===
                                                                                    vault
                                                                                        .selectedFiles[0]
                                                                                        .id,
                                                                            );
                                                                        if (fileIdx > -1) {
                                                                            updatedFiles[fileIdx] =
                                                                                {
                                                                                    ...updatedFiles[
                                                                                        fileIdx
                                                                                    ],
                                                                                    use_original:
                                                                                        !checked,
                                                                                    url: res.data
                                                                                        .url,
                                                                                };
                                                                            vault.setFiles(
                                                                                updatedFiles,
                                                                            );
                                                                            vault.setSelectedFiles([
                                                                                updatedFiles[
                                                                                    fileIdx
                                                                                ],
                                                                            ]);
                                                                            if (
                                                                                vault
                                                                                    .lastSelectedFile
                                                                                    ?.id ===
                                                                                vault
                                                                                    .selectedFiles[0]
                                                                                    .id
                                                                            ) {
                                                                                vault.setLastSelectedFile(
                                                                                    updatedFiles[
                                                                                        fileIdx
                                                                                    ],
                                                                                );
                                                                            }
                                                                        }
                                                                        toast.success(
                                                                            checked
                                                                                ? 'Serving optimized WebP'
                                                                                : 'Serving original image',
                                                                        );
                                                                    } catch {
                                                                        toast.error(
                                                                            'Failed to change optimization setting',
                                                                        );
                                                                    }
                                                                }}
                                                            />
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <div className="aspect-square w-full rounded-lg border bg-muted/20 flex flex-col items-center justify-center text-muted-foreground">
                                                <FileIcon className="h-16 w-16 mb-2" />
                                                <span className="font-medium">
                                                    {vault.selectedFiles.length} items selected
                                                </span>
                                            </div>
                                            <div className="grid gap-4 py-4 border-y text-sm">
                                                <div className="grid grid-cols-2">
                                                    <span className="text-muted-foreground">
                                                        Total Size
                                                    </span>
                                                    <span>
                                                        {(
                                                            vault.selectedFiles.reduce(
                                                                (acc, f) => acc + f.size_bytes,
                                                                0,
                                                            ) /
                                                            1024 /
                                                            1024
                                                        ).toFixed(2)}{' '}
                                                        MB
                                                    </span>
                                                </div>
                                            </div>
                                        </>
                                    )}

                                    <div className="flex flex-col gap-2">
                                        {vault.isTrashView ? (
                                            <>
                                                <Button
                                                    variant="outline"
                                                    onClick={vault.handleBatchRestore}
                                                    className="w-full text-green-600 border-green-200 hover:bg-green-50 hover:text-green-700"
                                                >
                                                    Restore {vault.selectedFiles.length > 1 && 'All'}
                                                </Button>
                                                <Button
                                                    variant="destructive"
                                                    onClick={vault.handleBatchForceDelete}
                                                    className="w-full"
                                                >
                                                    <Trash2 className="mr-2 h-4 w-4" /> Delete
                                                    Permanently
                                                </Button>
                                            </>
                                        ) : (
                                            <>
                                                {vault.selectedFiles.length === 1 && (
                                                    <Button
                                                        variant="outline"
                                                        onClick={() =>
                                                            window.open(
                                                                route(
                                                                    'admin.vault.file.serve',
                                                                    vault.selectedFiles[0].uuid,
                                                                ),
                                                                '_blank',
                                                            )
                                                        }
                                                        className="w-full"
                                                    >
                                                        <Download className="mr-2 h-4 w-4" />{' '}
                                                        Download
                                                    </Button>
                                                )}
                                                <Button
                                                    variant="outline"
                                                    className="w-full"
                                                    onClick={() => {
                                                        vault.setMoveTarget(null);
                                                        vault.setIsMoveOpen(true);
                                                    }}
                                                >
                                                    <Move className="mr-2 h-4 w-4" /> Move to Folder
                                                </Button>
                                                <Button
                                                    variant="destructive"
                                                    onClick={vault.handleBatchDelete}
                                                    className="w-full"
                                                >
                                                    <Trash2 className="mr-2 h-4 w-4" /> Delete{' '}
                                                    {vault.selectedFiles.length > 1 && 'All'}
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <div className="h-full flex flex-col items-center justify-center text-muted-foreground p-6 text-center">
                                    <Info className="h-12 w-12 mb-4 opacity-50" />
                                    <p>Select a file to view details</p>
                                </div>
                            )}
                        </ScrollArea>
                    </ResizablePanel>
                </ResizablePanelGroup>
            </div>

            <VaultUploadDialog
                open={vault.isUploadOpen}
                onOpenChange={vault.setIsUploadOpen}
                currentFolderId={vault.currentFolder?.id || null}
                onUploadComplete={() => vault.refresh(vault.currentFolder?.id || null)}
                maxUploadSize={maxUploadSize}
            />

            <VaultDialogs
                isCreateFolderOpen={vault.isCreateFolderOpen}
                setIsCreateFolderOpen={vault.setIsCreateFolderOpen}
                newFolderName={vault.newFolderName}
                setNewFolderName={vault.setNewFolderName}
                handleCreateFolder={vault.handleCreateFolder}
                isMoveOpen={vault.isMoveOpen}
                setIsMoveOpen={vault.setIsMoveOpen}
                folders={vault.folders}
                selectedCount={vault.selectedFiles.length}
                selectedFolderIds={vault.selectedFiles.map((f) => f.folder_id)}
                moveTarget={vault.moveTarget}
                setMoveTarget={vault.setMoveTarget}
                handleMove={vault.handleMove}
            />
        </AuthenticatedLayout>
    );
}
