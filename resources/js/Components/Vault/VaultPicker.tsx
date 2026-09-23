import { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { usePage } from '@inertiajs/react';
import UploadPipelineTracker, { PipelineStage } from './UploadPipelineTracker';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Progress } from '@/Components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { VaultFile, VaultFolder } from '@/types/vault';
import VaultThumbnail from './VaultThumbnail';
import VaultBreadcrumb from './VaultBreadcrumb';
import { Upload, X, Loader2, FolderPlus } from 'lucide-react';
import { toast } from 'sonner'; // Assuming sonner is used for toasts based on list_dir
import { cn } from '@/lib/utils'; // Assuming utils exists

interface VaultPickerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mode?: 'single' | 'multiple';
    onSelect: (files: VaultFile[]) => void;
    allowedTypes?: 'image' | 'document' | 'all'; // Filter
    allowUpload?: boolean;
}

interface FileUploadState {
    id: string; // unique ID for key
    file: File;
    progress: number;
    status: 'idle' | 'uploading' | 'processing' | 'completed' | 'error';
    errorStage?: PipelineStage | null;
    errorMessage?: string | null;
}

export default function VaultPicker({
    open,
    onOpenChange,
    mode = 'single',
    onSelect,
    allowedTypes = 'all',
    allowUpload = false,
}: VaultPickerProps) {
    const [activeTab, setActiveTab] = useState('library');
    const [files, setFiles] = useState<VaultFile[]>([]);
    const [folders, setFolders] = useState<VaultFolder[]>([]);
    const [currentFolder, setCurrentFolder] = useState<VaultFolder | null>(null);
    const [ancestors, setAncestors] = useState<VaultFolder[]>([]); // For breadcrumb
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState('');
    const [selectedFiles, setSelectedFiles] = useState<VaultFile[]>([]);
    const [uploads, setUploads] = useState<FileUploadState[]>([]);
    // const [uploadQueue, setUploadQueue] = useState<File[]>([]); // Replaced by uploads
    // const [uploadProgress, setUploadProgress] = useState<Record<string, number>>({}); // Replaced by uploads state
    const [isUploading, setIsUploading] = useState(false);
    const [filterType, setFilterType] = useState<'all' | 'image' | 'document'>(allowedTypes);

    // Fetch files and folders
    const loadContent = useCallback(async (folderId: string | null = null, typeOverride?: 'all' | 'image' | 'document') => {
        setLoading(true);
        const actualType = typeOverride !== undefined ? typeOverride : filterType;
        try {
            const [filesRes, foldersRes] = await Promise.all([
                axios.get(route('admin.vault.files.list'), {
                    params: { folder_id: folderId, search, type: actualType === 'all' ? undefined : actualType }
                }),
                axios.get(route('admin.vault.folders.list'), { params: { all: 1 } })
            ]);

            setFiles(filesRes.data.data); // Paginated response

            const allFolders = foldersRes.data;
            setFolders(allFolders.filter((f: any) => f.parent_id === folderId));

            if (folderId) {
                const current = allFolders.find((f: any) => f.id === folderId);
                // Prevent React re-render looping by retaining the exact memory reference if IDs match
                setCurrentFolder(prev => prev?.id === folderId ? prev : (current || null));

                const trail: VaultFolder[] = [];
                let curr = current;
                while (curr && curr.parent_id) {
                    const parent = allFolders.find((f: any) => f.id === curr.parent_id);
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

        } catch {
            toast.error('Failed to load library');
        } finally {
            setLoading(false);
        }
    }, [search, filterType]);

    // Sync filterType with allowedTypes prop when opening
    useEffect(() => {
        if (open) {
            setFilterType(allowedTypes);
            loadContent(currentFolder?.id || null, allowedTypes);
            setSelectedFiles([]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, allowedTypes]);

    const handleFileSelect = useCallback((file: VaultFile) => {
        if (mode === 'single') {
            setSelectedFiles([file]);
        } else {
            setSelectedFiles(prev => {
                const exists = prev.find(f => f.id === file.id);
                if (exists) return prev.filter(f => f.id !== file.id);
                return [...prev, file];
            });
        }
    }, [mode]);

    const handleConfirm = () => {
        const origin = window.location.origin;
        const processedFiles = selectedFiles.map(f => ({
            ...f,
            url: f.url?.replace(origin, '') || f.url
        }));
        onSelect(processedFiles);
        onOpenChange(false);
    };

    const handleThumbnailDoubleClick = useCallback((file: VaultFile) => {
        if (mode === 'single') {
            const origin = window.location.origin;
            const processedFile = {
                ...file,
                url: file.url?.replace(origin, '') || file.url
            };
            onSelect([processedFile]);
            onOpenChange(false);
        } else {
            handleFileSelect(file);
        }
    }, [mode, onSelect, onOpenChange, handleFileSelect]);

    const handleUploadDrop = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        const droppedFiles = Array.from(e.dataTransfer.files);
        const newUploads = droppedFiles.map(file => ({
            id: Math.random().toString(36).substring(7),
            file,
            progress: 0,
            status: 'idle' as const
        }));
        setUploads(prev => [...prev, ...newUploads]);
        setActiveTab('upload');
    }, []);

    const updateUploadState = useCallback((id: string, updates: Partial<FileUploadState>) => {
        setUploads(prev => prev.map(u => u.id === id ? { ...u, ...updates } : u));
    }, []);

    const processUpload = useCallback(async (upload: FileUploadState) => {
        setIsUploading(true);
        updateUploadState(upload.id, { status: 'uploading', progress: 0 });

        const formData = new FormData();
        formData.append('files[]', upload.file);
        if (currentFolder) {
            formData.append('folder_id', currentFolder.id);
        }

        try {
            const res = await axios.post(route('admin.vault.upload'), formData, {
                onUploadProgress: (progressEvent) => {
                    const percent = Math.round((progressEvent.loaded * 100) / (progressEvent.total || 100));
                    updateUploadState(upload.id, { progress: percent });
                }
            });

            // Upload complete -> 'Processing'
            updateUploadState(upload.id, { status: 'processing', progress: 100 });
            await new Promise(resolve => setTimeout(resolve, 800));

            const { uploaded, errors } = res.data;
            const hasError = errors && errors.some((e: any) => e.filename === upload.file.name);

            if (hasError) {
                const errorData = errors.find((e: any) => e.filename === upload.file.name);
                const errorMsg = errorData.error;

                let failedStage: PipelineStage = 'ValidateMimeType';
                if (errorMsg.includes('Double extension')) failedStage = 'DetectDoubleExtension';
                else if (errorMsg.includes('MIME')) failedStage = 'ValidateMimeType';
                else if (errorMsg.includes('Virus')) failedStage = 'SandboxedScan';
                else if (errorMsg.includes('uuid')) failedStage = 'GenerateUuid';
                else if (errorMsg.includes('metadata')) failedStage = 'StoreMetadata';

                await new Promise(resolve => setTimeout(resolve, 1500));

                updateUploadState(upload.id, {
                    status: 'error',
                    errorStage: failedStage,
                    errorMessage: errorMsg
                });
            } else {
                // Success
                await new Promise(resolve => setTimeout(resolve, 3000));
                updateUploadState(upload.id, { status: 'completed' });

                // If single mode, auto-select?
                const uploadedFile = uploaded.find((u: any) => u.original_name === upload.file.name);
                if (uploadedFile) {
                    // Add to selection if single mode? Or just refresh library?
                    // Verify logic: "On completion auto-switch". 
                    // We should refresh library always.
                    loadContent(currentFolder?.id || null);

                    if (mode === 'single' && uploads.length === 1) { // Only if it's the only upload
                        handleFileSelect(uploadedFile);
                    }
                }
            }

        } catch (error: any) {
            let msg = 'Upload failed';
            if (error.response?.status === 413) msg = 'File too large';
            else if (error.response?.status === 422) msg = error.response.data.message;

            updateUploadState(upload.id, {
                status: 'error',
                errorMessage: msg,
                errorStage: 'ValidateMimeType'
            });
        } finally {
            setIsUploading(false);
        }
    }, [currentFolder, loadContent, mode, uploads.length, handleFileSelect]); // Added dependencies

    // Process Queue
    useEffect(() => {
        const nextUpload = uploads.find(u => u.status === 'idle');
        if (nextUpload && !isUploading) {
            processUpload(nextUpload);
        }
    }, [uploads, isUploading, processUpload]);

    const removeUpload = (id: string) => {
        setUploads(prev => prev.filter(u => u.id !== id));
    };

    const clearCompleted = () => {
        setUploads(prev => prev.filter(u => u.status !== 'completed'));
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {/* High z-index so the picker sits above any dialog that opened it */}
            <DialogContent className="max-w-4xl h-[80vh] flex flex-col p-0 gap-0 z-2000">
                <DialogHeader className="p-4 border-b">
                    <DialogTitle>Media Vault</DialogTitle>
                </DialogHeader>

                <Tabs value={activeTab} onValueChange={setActiveTab} className="flex-1 flex flex-col overflow-hidden">
                    <div className="px-4 py-2 border-b flex items-center justify-between bg-muted/20">
                        <TabsList>
                            <TabsTrigger value="library">Library</TabsTrigger>
                            {allowUpload && <TabsTrigger value="upload">Upload</TabsTrigger>}
                        </TabsList>

                        {activeTab === 'library' && (
                            <div className="flex items-center gap-2">
                                <Input
                                    placeholder="Search..."
                                    className="h-8 w-[150px] lg:w-[250px]"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                                <Select value={filterType} onValueChange={(v: any) => setFilterType(v)}>
                                    <SelectTrigger className="h-8 w-[100px]">
                                        <SelectValue placeholder="Type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="image">Images</SelectItem>
                                        <SelectItem value="document">Docs</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                    </div>

                    <TabsContent value="library" className="flex-1 flex flex-col min-h-0 m-0">
                        {/* Breadcrumbs */}
                        <div className="px-4 py-2 border-b bg-background flex items-center gap-2">
                            <VaultBreadcrumb
                                folder={currentFolder}
                                ancestors={ancestors}
                                onNavigate={(id) => { setCurrentFolder(folders.find((f: any) => f.id === id) || null); loadContent(id); }}
                            />
                        </div>

                        <ScrollArea className="flex-1 p-4">
                            {loading ? (
                                <div className="flex items-center justify-center h-full">
                                    <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                                </div>
                            ) : (
                                <div className="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-5 gap-4">
                                    {/* Folders */}
                                    {folders.map(folder => (
                                        <div
                                            key={folder.id}
                                            className="flex flex-col items-center gap-2 p-4 border rounded-lg hover:bg-accent cursor-pointer transition-colors"
                                            onClick={() => { setCurrentFolder(folder); loadContent(folder.id); }}
                                        >
                                            <FolderPlus className="h-12 w-12 text-blue-400" />
                                            <span className="text-sm font-medium truncate w-full text-center">{folder.name}</span>
                                        </div>
                                    ))}

                                    {/* Files */}
                                    {files.map(file => (
                                        <VaultThumbnail
                                            key={file.id}
                                            file={file}
                                            selected={selectedFiles.some(f => f.id === file.id)}
                                            onSelect={() => handleFileSelect(file)}
                                            onDoubleClick={() => handleThumbnailDoubleClick(file)}
                                        />
                                    ))}

                                    {folders.length === 0 && files.length === 0 && (
                                        <div className="col-span-full py-10 text-center text-muted-foreground">
                                            No files found in this folder.
                                        </div>
                                    )}
                                </div>
                            )}
                        </ScrollArea>
                    </TabsContent>

                    {allowUpload && (
                        <TabsContent value="upload" className="flex-1 p-4 m-0 overflow-y-auto">
                            <div
                                className="border-2 border-dashed rounded-xl h-[150px] flex flex-col items-center justify-center text-muted-foreground bg-muted/10 hover:bg-muted/30 transition-colors mb-4"
                                onDragOver={(e) => e.preventDefault()}
                                onDrop={handleUploadDrop}
                            >
                                <Upload className="h-8 w-8 mb-2" />
                                <p>Drag & Drop files here</p>
                                <Input
                                    type="file"
                                    className="hidden"
                                    multiple
                                    onChange={(e) => {
                                        if (e.target.files) {
                                            const newFiles = Array.from(e.target.files);
                                            const newUploads = newFiles.map(file => ({
                                                id: Math.random().toString(36).substring(7),
                                                file,
                                                progress: 0,
                                                status: 'idle' as const
                                            }));
                                            setUploads(prev => [...prev, ...newUploads]);
                                        }
                                    }}
                                />
                            </div>

                            {/* Uploads List */}
                            <div className="space-y-3">
                                {uploads.map(upload => (
                                    <div key={upload.id} className="relative group">
                                        <UploadPipelineTracker
                                            file={upload.file}
                                            uploadProgress={upload.progress}
                                            status={upload.status}
                                            errorStage={upload.errorStage}
                                            errorMessage={upload.errorMessage}
                                        />
                                        {upload.status !== 'uploading' && upload.status !== 'processing' && (
                                            <button
                                                onClick={() => removeUpload(upload.id)}
                                                className="absolute top-2 right-2 text-muted-foreground hover:text-destructive opacity-0 group-hover:opacity-100 transition-opacity"
                                            >
                                                <X className="h-4 w-4" />
                                            </button>
                                        )}
                                    </div>
                                ))}
                            </div>

                            {uploads.length > 0 && (
                                <div className="mt-4 flex justify-end">
                                    <Button variant="ghost" size="sm" onClick={clearCompleted} disabled={!uploads.some(u => u.status === 'completed')}>
                                        Clear Completed
                                    </Button>
                                </div>
                            )}
                        </TabsContent>
                    )}
                </Tabs>

                <DialogFooter className="p-4 border-t">
                    <div className="flex-1 flex justify-between items-center">
                        <div className="text-sm text-muted-foreground">
                            {selectedFiles.length} file(s) selected
                        </div>
                        <div className="flex gap-2">
                            <Button variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
                            <Button onClick={handleConfirm} disabled={selectedFiles.length === 0}>
                                Insert {selectedFiles.length > 0 ? `(${selectedFiles.length})` : ''}
                            </Button>
                        </div>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
