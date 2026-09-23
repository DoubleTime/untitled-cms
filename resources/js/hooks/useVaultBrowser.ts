import { useState, useEffect, useCallback, useRef } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { VaultFile, VaultFolder } from '@/types/vault';

const SEARCH_DEBOUNCE_MS = 300;

/** Prefer the server's error/message (e.g. 422 name collisions) over a generic fallback. */
function apiErrorMessage(e: unknown, fallback: string): string {
    const data = (e as { response?: { data?: { error?: string; message?: string } } }).response
        ?.data;
    return data?.error || data?.message || fallback;
}

export function useVaultBrowser() {
    const [folders, setFolders] = useState<VaultFolder[]>([]);
    const [files, setFiles] = useState<VaultFile[]>([]);
    const [currentFolder, setCurrentFolder] = useState<VaultFolder | null>(null);
    const [ancestors, setAncestors] = useState<VaultFolder[]>([]);
    const [selectedFiles, setSelectedFiles] = useState<VaultFile[]>([]);
    const [lastSelectedFile, setLastSelectedFile] = useState<VaultFile | null>(null);
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [loading, setLoading] = useState(false);
    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
    const [isTrashView, setIsTrashView] = useState(false);

    const [isCreateFolderOpen, setIsCreateFolderOpen] = useState(false);
    const [newFolderName, setNewFolderName] = useState('');
    const [isUploadOpen, setIsUploadOpen] = useState(false);
    const [isMoveOpen, setIsMoveOpen] = useState(false);
    const [moveTarget, setMoveTarget] = useState<string | null>(null);

    // Draft value of the alt-text box, so typing does not need a re-fetch.
    const [generatedAltText, setGeneratedAltText] = useState<string | null>(null);

    // Only the latest refresh may write state; earlier in-flight responses are dropped.
    const requestSeq = useRef(0);

    useEffect(() => {
        const savedView = localStorage.getItem('vaultViewMode');
        if (savedView === 'grid' || savedView === 'list') {
            setViewMode(savedView);
        }
    }, []);

    useEffect(() => {
        localStorage.setItem('vaultViewMode', viewMode);
    }, [viewMode]);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), SEARCH_DEBOUNCE_MS);
        return () => clearTimeout(timer);
    }, [search]);

    const refresh = useCallback(
        (folderId: string | null = null, trashTarget: boolean = isTrashView) => {
            const seq = ++requestSeq.current;
            setLoading(true);
            const params: Record<string, string | null> = {
                folder_id: folderId,
                search: debouncedSearch,
            };
            const endpoint = trashTarget
                ? route('admin.vault.trash.list')
                : route('admin.vault.files.list');

            Promise.all([
                axios.get(endpoint, { params }),
                axios.get(route('admin.vault.folders.list'), { params: { all: 1 } }),
            ])
                .then(([filesRes, foldersRes]) => {
                    if (seq !== requestSeq.current) return;

                    setFiles(filesRes.data.data || filesRes.data);
                    setFolders(foldersRes.data);

                    if (folderId) {
                        const current = foldersRes.data.find((f: VaultFolder) => f.id === folderId);
                        setCurrentFolder(current || null);
                        const trail: VaultFolder[] = [];
                        let curr = current;
                        while (curr && curr.parent_id) {
                            const parent = foldersRes.data.find(
                                (f: VaultFolder) => f.id === curr.parent_id,
                            );
                            if (parent) {
                                trail.unshift(parent);
                                curr = parent;
                            } else {
                                break;
                            }
                        }
                        setAncestors(trail);
                    } else {
                        setCurrentFolder(null);
                        setAncestors([]);
                    }
                    setSelectedFiles([]);
                    setLastSelectedFile(null);
                    setGeneratedAltText(null);
                })
                .catch(() => {
                    if (seq !== requestSeq.current) return;
                    toast.error('Failed to load vault data');
                })
                .finally(() => {
                    if (seq === requestSeq.current) setLoading(false);
                });
        },
        [debouncedSearch, isTrashView],
    );

    useEffect(() => {
        refresh(currentFolder?.id || null);
    }, [refresh, currentFolder?.id]);

    const handleMove = async () => {
        if (!selectedFiles.length) return;
        try {
            await axios.post(route('admin.vault.files.batch_move'), {
                uuids: selectedFiles.map((f) => f.uuid),
                folder_id: moveTarget || null,
            });
            const dest = moveTarget
                ? folders.find((f) => f.id === moveTarget)?.name || 'folder'
                : 'Root';
            toast.success(`Moved ${selectedFiles.length} item(s) to ${dest}`);
            setIsMoveOpen(false);
            setSelectedFiles([]);
            setMoveTarget(null);
            refresh(currentFolder?.id || null);
        } catch (e: unknown) {
            toast.error(apiErrorMessage(e, 'Move failed.'));
        }
    };

    const handleCreateFolder = async () => {
        if (!newFolderName) return;
        try {
            await axios.post(route('admin.vault.folders.store'), {
                name: newFolderName,
                parent_id: currentFolder?.id,
            });
            toast.success('Folder created');
            setIsCreateFolderOpen(false);
            setNewFolderName('');
            refresh(currentFolder?.id || null);
        } catch (e: unknown) {
            toast.error(apiErrorMessage(e, 'Failed to create folder'));
        }
    };

    const handleDelete = async (type: 'file' | 'folder', id: string) => {
        if (!confirm('Are you sure you want to delete this item?')) return;
        try {
            const routeName =
                type === 'file' ? 'admin.vault.file.destroy' : 'admin.vault.folders.destroy';
            await axios.delete(route(routeName, id));
            toast.success(`${type === 'file' ? 'File' : 'Folder'} deleted`);
            if (type === 'file') setSelectedFiles((prev) => prev.filter((f) => f.uuid !== id));
            refresh(currentFolder?.id || null);
        } catch (e: unknown) {
            toast.error(apiErrorMessage(e, 'Failed to delete item'));
        }
    };

    const handleRestore = async (type: 'file' | 'folder', id: string) => {
        try {
            const routeName =
                type === 'file' ? 'admin.vault.file.restore' : 'admin.vault.folders.restore';
            await axios.post(route(routeName, id));
            toast.success(`${type === 'file' ? 'File' : 'Folder'} restored`);
            if (type === 'file') setSelectedFiles((prev) => prev.filter((f) => f.uuid !== id));
            refresh(currentFolder?.id || null);
        } catch (e: unknown) {
            toast.error(apiErrorMessage(e, 'Failed to restore item'));
        }
    };

    const handleForceDelete = async (type: 'file' | 'folder', id: string) => {
        if (
            !confirm(
                'Are you sure you want to PERMANENTLY delete this item? This cannot be undone.',
            )
        )
            return;
        try {
            const routeName =
                type === 'file'
                    ? 'admin.vault.file.force_destroy'
                    : 'admin.vault.folders.force_destroy';
            await axios.delete(route(routeName, id));
            toast.success(`${type === 'file' ? 'File' : 'Folder'} permanently deleted`);
            if (type === 'file') setSelectedFiles((prev) => prev.filter((f) => f.uuid !== id));
            refresh(currentFolder?.id || null);
        } catch (e: unknown) {
            toast.error(apiErrorMessage(e, 'Failed to permanently delete item'));
        }
    };

    /** Restores the selection in one request (per-file policy checks run server-side). */
    const handleBatchRestore = async () => {
        if (!selectedFiles.length) return;
        try {
            const res = await axios.post(route('admin.vault.files.batch_restore'), {
                uuids: selectedFiles.map((f) => f.uuid),
            });
            toast.success(res.data.message || 'Items restored');
            setSelectedFiles([]);
            refresh(currentFolder?.id || null);
        } catch (e: unknown) {
            toast.error(apiErrorMessage(e, 'Restore failed.'));
        }
    };

    const handleBatchDelete = async () => {
        if (!selectedFiles.length) return;
        if (!confirm(`Are you sure you want to delete these ${selectedFiles.length} items?`)) return;
        try {
            const res = await axios.post(route('admin.vault.files.batch_delete'), {
                uuids: selectedFiles.map((f) => f.uuid),
            });
            toast.success(res.data.message || 'Items deleted');
            setSelectedFiles([]);
            refresh(currentFolder?.id || null);
        } catch (e: unknown) {
            toast.error(apiErrorMessage(e, 'Delete failed.'));
        }
    };

    /** No batch purge endpoint exists; report partial failures instead of failing silently. */
    const handleBatchForceDelete = async () => {
        if (!selectedFiles.length) return;
        if (
            !confirm(
                `Are you sure you want to PERMANENTLY delete these ${selectedFiles.length} items?`,
            )
        )
            return;
        const results = await Promise.allSettled(
            selectedFiles.map((f) => axios.delete(route('admin.vault.file.force_destroy', f.uuid))),
        );
        const failed = results.filter((r) => r.status === 'rejected').length;
        const deleted = results.length - failed;
        if (deleted) toast.success(`${deleted} item(s) permanently deleted`);
        if (failed) toast.error(`${failed} item(s) could not be permanently deleted`);
        setSelectedFiles([]);
        refresh(currentFolder?.id || null);
    };

    const handleSelectFile = (e: React.MouseEvent, file: VaultFile) => {
        e.stopPropagation();
        // Unsaved generated alt text belongs to the previous selection
        setGeneratedAltText(null);
        if (e.shiftKey && lastSelectedFile) {
            const startIdx = files.findIndex((f) => f.id === lastSelectedFile.id);
            const endIdx = files.findIndex((f) => f.id === file.id);
            if (startIdx !== -1 && endIdx !== -1) {
                const min = Math.min(startIdx, endIdx);
                const max = Math.max(startIdx, endIdx);
                const range = files.slice(min, max + 1);

                if (e.metaKey || e.ctrlKey) {
                    const newSelection = [...selectedFiles];
                    range.forEach((r) => {
                        if (!newSelection.find((s) => s.id === r.id)) newSelection.push(r);
                    });
                    setSelectedFiles(newSelection);
                } else {
                    setSelectedFiles(range);
                }
                setLastSelectedFile(file);
            }
        } else if (e.metaKey || e.ctrlKey) {
            const isSelected = selectedFiles.some((f) => f.id === file.id);
            if (isSelected) {
                setSelectedFiles(selectedFiles.filter((f) => f.id !== file.id));
            } else {
                setSelectedFiles([...selectedFiles, file]);
            }
            setLastSelectedFile(file);
        } else {
            setSelectedFiles([file]);
            setLastSelectedFile(file);
        }
    };

    // Navigation only mutates state; the refresh effect reloads once when
    // isTrashView / currentFolder / search change (avoids double-fetch).
    const openTrash = () => {
        setIsTrashView(true);
        setCurrentFolder(null);
    };

    const openRoot = () => {
        setIsTrashView(false);
        setCurrentFolder(null);
    };

    const openFolder = (folder: VaultFolder | null) => {
        setIsTrashView(false);
        setCurrentFolder(folder);
    };

    return {
        folders,
        files,
        setFiles,
        currentFolder,
        setCurrentFolder,
        ancestors,
        selectedFiles,
        setSelectedFiles,
        lastSelectedFile,
        setLastSelectedFile,
        search,
        setSearch,
        loading,
        viewMode,
        setViewMode,
        isTrashView,
        setIsTrashView,
        isCreateFolderOpen,
        setIsCreateFolderOpen,
        newFolderName,
        setNewFolderName,
        isUploadOpen,
        setIsUploadOpen,
        isMoveOpen,
        setIsMoveOpen,
        moveTarget,
        setMoveTarget,
        generatedAltText,
        setGeneratedAltText,
        refresh,
        handleMove,
        handleCreateFolder,
        handleDelete,
        handleRestore,
        handleForceDelete,
        handleBatchRestore,
        handleBatchDelete,
        handleBatchForceDelete,
        handleSelectFile,
        openTrash,
        openRoot,
        openFolder,
    };
}

export type VaultBrowserState = ReturnType<typeof useVaultBrowser>;
