
import React, { useEffect, useState } from "react";
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
    CommandShortcut,
} from "@/Components/ui/command";
import { useUI } from "@/Contexts/UIContext";
import { router } from "@inertiajs/react";
import {
    Calculator,
    Calendar,
    Settings,
    Smile,
    User
} from "lucide-react";

export function GlobalCommandPalette() {
    const { isCommandPaletteOpen, setCommandPaletteOpen } = useUI();

    useEffect(() => {
        const down = (e: KeyboardEvent) => {
            if (e.key === "k" && (e.metaKey || e.ctrlKey)) {
                e.preventDefault();
                setCommandPaletteOpen(!isCommandPaletteOpen);
            }
        }
        document.addEventListener("keydown", down);
        return () => document.removeEventListener("keydown", down);
    }, [isCommandPaletteOpen, setCommandPaletteOpen]);

    const runCommand = React.useCallback((command: () => unknown) => {
        setCommandPaletteOpen(false);
        command();
    }, [setCommandPaletteOpen]);

    return (
        <CommandDialog
            open={isCommandPaletteOpen}
            onOpenChange={setCommandPaletteOpen}
        >
            <CommandInput placeholder="Type a command or search..." />
            <CommandList>
                <CommandEmpty>No results found.</CommandEmpty>
                <CommandGroup heading="Suggestions">
                    <CommandItem onSelect={() => runCommand(() => router.visit(route('admin.dashboard')))}>
                        <Calendar className="mr-2 h-4 w-4" />
                        <span>Dashboard</span>
                    </CommandItem>
                    <CommandItem onSelect={() => runCommand(() => router.visit(route('admin.profile.edit')))}>
                        <Smile className="mr-2 h-4 w-4" />
                        <span>Profile</span>
                    </CommandItem>
                </CommandGroup>
                <CommandSeparator />
                <CommandGroup heading="Settings">
                    <CommandItem onSelect={() => runCommand(() => router.visit(route('admin.profile.edit')))}>
                        <User className="mr-2 h-4 w-4" />
                        <span>Profile</span>
                        <CommandShortcut>⌘P</CommandShortcut>
                    </CommandItem>
                    <CommandItem onSelect={() => runCommand(() => router.visit(route('admin.settings.index')))}>
                        <Settings className="mr-2 h-4 w-4" />
                        <span>Settings</span>
                        <CommandShortcut>⌘S</CommandShortcut>
                    </CommandItem>
                </CommandGroup>
            </CommandList>
        </CommandDialog>
    );
}
