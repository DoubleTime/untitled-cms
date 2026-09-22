import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    rectSortingStrategy,
    sortableKeyboardCoordinates,
    useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { GripVertical, ImagePlus, Trash2 } from 'lucide-react';
import { useVaultPicker } from '@/hooks/use-vault-picker';
import type { ScriptImage } from '@/types';

export interface PreviewImage {
    vault_file_id: string;
    url: string;
    name: string;
}

interface PreviewImagesManagerProps {
    scriptId: string;
    images: ScriptImage[];
    canEdit: boolean;
}

function SortableImage({
    image,
    index,
    canEdit,
    onRemove,
}: {
    image: PreviewImage;
    index: number;
    canEdit: boolean;
    onRemove: () => void;
}) {
    const { attributes, listeners, setNodeRef, transform, transition } = useSortable({
        id: image.vault_file_id,
    });

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className="relative group aspect-video overflow-hidden rounded-md border bg-background"
        >
            <img src={image.url} alt={image.name} className="h-full w-full object-cover" />
            {index === 0 && (
                <Badge className="absolute left-2 top-2" variant="secondary">
                    Cover
                </Badge>
            )}
            {canEdit && (
                <div className="absolute inset-0 flex items-center justify-center gap-2 bg-black/40 opacity-0 transition-opacity group-hover:opacity-100">
                    <Button
                        type="button"
                        variant="secondary"
                        size="icon"
                        className="h-8 w-8 cursor-grab active:cursor-grabbing"
                        {...attributes}
                        {...listeners}
                    >
                        <GripVertical className="h-4 w-4" />
                    </Button>
                    <Button type="button" variant="destructive" size="icon" className="h-8 w-8" onClick={onRemove}>
                        <Trash2 className="h-4 w-4" />
                    </Button>
                </div>
            )}
        </div>
    );
}

/**
 * Preview Images for a Script — flow diagrams or screenshots so Team
 * Members and Customer Users can recognise it before downloading. They are
 * ordinary public Vault media; the first image is the cover.
 */
export default function PreviewImagesManager({ scriptId, images, canEdit }: PreviewImagesManagerProps) {
    const toPreview = (list: ScriptImage[]): PreviewImage[] =>
        list
            .filter((image) => image.vault_file)
            .map((image) => ({
                vault_file_id: image.vault_file_id,
                url: image.vault_file!.url,
                name: image.vault_file!.original_name,
            }));

    const [items, setItems] = useState<PreviewImage[]>(() => toPreview(images));
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const { openPicker } = useVaultPicker();

    useEffect(() => {
        setItems(toPreview(images));
        setDirty(false);
    }, [images]);

    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    );

    const handleDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;

        if (over && active.id !== over.id) {
            const oldIndex = items.findIndex((image) => image.vault_file_id === active.id);
            const newIndex = items.findIndex((image) => image.vault_file_id === over.id);
            setItems(arrayMove(items, oldIndex, newIndex));
            setDirty(true);
        }
    };

    const addImages = () => {
        openPicker({
            mode: 'multiple',
            type: 'image',
            onSelect: (files) => {
                const picked = files as unknown as {
                    id: string;
                    url: string;
                    original_name: string;
                }[];

                setItems((current) => {
                    const existing = new Set(current.map((image) => image.vault_file_id));
                    const added = picked
                        .filter((file) => !existing.has(file.id))
                        .map((file) => ({
                            vault_file_id: file.id,
                            url: file.url,
                            name: file.original_name,
                        }));

                    return [...current, ...added];
                });
                setDirty(true);
            },
        });
    };

    const save = () => {
        setSaving(true);
        router.put(
            route('admin.marketplace.scripts.images.sync', scriptId),
            { vault_file_ids: items.map((image) => image.vault_file_id) },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSaving(false);
                    setDirty(false);
                },
            }
        );
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-4">
                <p className="text-sm text-muted-foreground">
                    Preview Images help Team Members and Customer Users recognise this Script. Drag to
                    reorder — the first image is the cover.
                </p>
                {canEdit && (
                    <div className="flex shrink-0 gap-2">
                        <Button size="sm" variant="outline" className="h-8" onClick={addImages}>
                            <ImagePlus className="mr-2 h-4 w-4" /> Add from Vault
                        </Button>
                        <Button size="sm" className="h-8" onClick={save} disabled={!dirty || saving}>
                            {saving ? 'Saving...' : 'Save order'}
                        </Button>
                    </div>
                )}
            </div>

            {items.length === 0 ? (
                <p className="text-sm text-muted-foreground">No Preview Images yet.</p>
            ) : (
                <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                    <SortableContext
                        items={items.map((image) => image.vault_file_id)}
                        strategy={rectSortingStrategy}
                    >
                        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                            {items.map((image, index) => (
                                <SortableImage
                                    key={image.vault_file_id}
                                    image={image}
                                    index={index}
                                    canEdit={canEdit}
                                    onRemove={() => {
                                        setItems((current) =>
                                            current.filter((i) => i.vault_file_id !== image.vault_file_id)
                                        );
                                        setDirty(true);
                                    }}
                                />
                            ))}
                        </div>
                    </SortableContext>
                </DndContext>
            )}
        </div>
    );
}
