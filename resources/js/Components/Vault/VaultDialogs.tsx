import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { VaultFolder } from '@/types/vault';
import { cn } from '@/lib/utils';
import { Folder, Move } from 'lucide-react';

type Props = {
    isCreateFolderOpen: boolean;
    setIsCreateFolderOpen: (v: boolean) => void;
    newFolderName: string;
    setNewFolderName: (v: string) => void;
    handleCreateFolder: () => void;
    isMoveOpen: boolean;
    setIsMoveOpen: (v: boolean) => void;
    folders: VaultFolder[];
    selectedCount: number;
    selectedFolderIds: (string | null | undefined)[];
    moveTarget: string | null;
    setMoveTarget: (v: string | null) => void;
    handleMove: () => void;
};

export default function VaultDialogs({
    isCreateFolderOpen,
    setIsCreateFolderOpen,
    newFolderName,
    setNewFolderName,
    handleCreateFolder,
    isMoveOpen,
    setIsMoveOpen,
    folders,
    selectedCount,
    selectedFolderIds,
    moveTarget,
    setMoveTarget,
    handleMove,
}: Props) {
    return (
        <>
            <Dialog open={isCreateFolderOpen} onOpenChange={setIsCreateFolderOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New Folder</DialogTitle>
                    </DialogHeader>
                    <div className="py-4">
                        <Input
                            placeholder="Folder Name"
                            value={newFolderName}
                            onChange={(e) => setNewFolderName(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleCreateFolder()}
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setIsCreateFolderOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={handleCreateFolder}>Create</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={isMoveOpen} onOpenChange={setIsMoveOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Move className="h-5 w-5 text-primary" />
                            Move {selectedCount} Item{selectedCount !== 1 ? 's' : ''} to Folder
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2 max-h-72 overflow-y-auto py-2">
                        <button
                            onClick={() => setMoveTarget(null)}
                            className={cn(
                                'w-full flex items-center gap-2 px-3 py-2 rounded-md text-sm text-left transition-colors',
                                moveTarget === null
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-muted',
                            )}
                        >
                            <Folder className="h-4 w-4 shrink-0" />
                            <span className="font-medium">Root</span>
                        </button>
                        {folders.map((folder) => (
                            <button
                                key={folder.id}
                                onClick={() => setMoveTarget(folder.id)}
                                disabled={selectedFolderIds.some((id) => id === folder.id)}
                                className={cn(
                                    'w-full flex items-center gap-2 px-3 py-2 rounded-md text-sm text-left transition-colors disabled:opacity-40 disabled:cursor-not-allowed',
                                    moveTarget === folder.id
                                        ? 'bg-primary text-primary-foreground'
                                        : 'hover:bg-muted',
                                )}
                            >
                                <Folder className="h-4 w-4 shrink-0" />
                                {folder.name}
                            </button>
                        ))}
                        {folders.length === 0 && (
                            <p className="text-sm text-muted-foreground text-center py-4">
                                No folders exist yet.
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setIsMoveOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={handleMove}>
                            <Move className="mr-2 h-4 w-4" /> Move Here
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

        </>
    );
}
