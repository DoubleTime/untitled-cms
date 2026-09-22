import { useCallback, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { useDropzone } from 'react-dropzone';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Progress } from '@/Components/ui/progress';
import { cn } from '@/lib/utils';
import { UploadCloud, FileArchive } from 'lucide-react';
import { formatBytes } from './format';

interface RevisionUploadDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Resolved URL of the revisions.store route for this catalogue entry. */
    uploadUrl: string;
    /** Extensions the entry type accepts, e.g. ['zip']. */
    allowedExtensions: string[];
    /** Upload cap in kilobytes, from config/marketplace.php. */
    maxUploadKb: number;
}

/**
 * Uploads a new Revision. Every Revision needs a change note; the file is
 * validated server-side against the entry type's extension, magic bytes and
 * the size cap, so the hints here only mirror the server's rules.
 */
export default function RevisionUploadDialog({
    open,
    onOpenChange,
    uploadUrl,
    allowedExtensions,
    maxUploadKb,
}: RevisionUploadDialogProps) {
    const [clientError, setClientError] = useState<string | null>(null);
    const { data, setData, post, processing, errors, progress, reset, clearErrors } = useForm<{
        file: File | null;
        change_note: string;
    }>({
        file: null,
        change_note: '',
    });

    const accept = allowedExtensions.map((extension) => `.${extension}`).join(', ');
    const maxMb = Math.round(maxUploadKb / 1024);

    const onDrop = useCallback(
        (accepted: File[]) => {
            const file = accepted[0];
            if (!file) {
                return;
            }

            const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

            if (allowedExtensions.length > 0 && !allowedExtensions.includes(extension)) {
                setClientError(`Only ${accept} files may be uploaded here.`);
                return;
            }

            if (file.size > maxUploadKb * 1024) {
                setClientError(`The file is larger than the ${maxMb} MB limit.`);
                return;
            }

            setClientError(null);
            setData('file', file);
        },
        [accept, allowedExtensions, maxMb, maxUploadKb, setData]
    );

    const { getRootProps, getInputProps, isDragActive } = useDropzone({ onDrop, multiple: false });

    const close = () => {
        reset();
        clearErrors();
        setClientError(null);
        onOpenChange(false);
    };

    const submit = () => {
        post(uploadUrl, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => close(),
        });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Upload Revision</DialogTitle>
                    <DialogDescription>
                        A Revision is immutable once uploaded. It starts as a draft — release it when it is ready
                        for RPA-TOOL. Accepted: {accept || 'n/a'}, up to {maxMb} MB.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div
                        {...getRootProps()}
                        className={cn(
                            'flex flex-col items-center justify-center gap-2 rounded-md border border-dashed p-6 text-center cursor-pointer transition-colors',
                            isDragActive ? 'border-primary bg-muted' : 'border-muted-foreground/30'
                        )}
                    >
                        <input {...getInputProps()} accept={accept} />
                        {data.file ? (
                            <>
                                <FileArchive className="h-6 w-6 text-muted-foreground" />
                                <p className="text-sm font-medium">{data.file.name}</p>
                                <p className="text-xs text-muted-foreground">{formatBytes(data.file.size)}</p>
                            </>
                        ) : (
                            <>
                                <UploadCloud className="h-6 w-6 text-muted-foreground" />
                                <p className="text-sm">Drop the file here, or click to browse</p>
                                <p className="text-xs text-muted-foreground">
                                    {accept || 'n/a'} · max {maxMb} MB
                                </p>
                            </>
                        )}
                    </div>

                    {clientError && <p className="text-sm text-destructive">{clientError}</p>}
                    {errors.file && <p className="text-sm text-destructive">{errors.file}</p>}

                    <div>
                        <Label htmlFor="change_note">Change note</Label>
                        <Textarea
                            id="change_note"
                            value={data.change_note}
                            onChange={(e) => setData('change_note', e.target.value)}
                            className="mt-1 block w-full"
                            rows={3}
                            placeholder="What changed in this Revision?"
                            required
                        />
                        {errors.change_note && (
                            <p className="text-sm text-destructive mt-1">{errors.change_note}</p>
                        )}
                    </div>

                    {progress && <Progress value={progress.percentage ?? 0} className="h-2" />}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={close} disabled={processing}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={processing || !data.file || data.change_note === ''}>
                        {processing ? 'Uploading...' : 'Upload Revision'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
