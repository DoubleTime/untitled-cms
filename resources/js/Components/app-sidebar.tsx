import * as React from "react"
import {
  BookOpen,
  Bot,
  Command,
  Frame,
  LayoutDashboard,
  LifeBuoy,
  Map,
  PieChart,
  Send,
  Settings2,
  SquareTerminal,
  FileText,
  Users,
  Shield,
  ImageIcon,
  LayoutPanelLeft,
  Activity,
  ExternalLink,
  Factory
} from "lucide-react"

import { NavMain } from "@/Components/nav-main"
import { NavProjects } from "@/Components/nav-projects"
import { NavUser } from "@/Components/nav-user"
import { TeamSwitcher } from "@/Components/team-switcher"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarRail,
} from "@/Components/ui/sidebar"
import { usePage } from "@inertiajs/react"
import { User } from '@/types';

import { NavSecondary } from "@/Components/nav-secondary"

export function AppSidebar({ ...props }: React.ComponentProps<typeof Sidebar>) {
  const { appName } = usePage().props;
  const user = usePage().props.auth.user;
  const permissions = usePage().props.auth.permissions ?? [];
  const { url } = usePage();

  // Marketplace entries appear only for the matching `<resource>.view` permission.
  const marketplaceItems = [
    ...(permissions.includes('customers.view')
      ? [{ title: "Customers", url: route('admin.marketplace.customers.index') }]
      : []),
    ...(permissions.includes('scripts.view')
      ? [{ title: "FlowChart Scripts", url: route('admin.marketplace.scripts.index') }]
      : []),
    ...(permissions.includes('ai_models.view')
      ? [{ title: "AI Models", url: route('admin.marketplace.ai-models.index') }]
      : []),
    ...(permissions.includes('machines.view')
      ? [
          { title: "Machine Brands", url: route('admin.marketplace.machine-brands.index') },
          { title: "Machine Models", url: route('admin.marketplace.machine-models.index') },
        ]
      : []),
    ...(permissions.includes('ai_boxes.view')
      ? [{ title: "AI Boxes", url: route('admin.marketplace.ai-boxes.index') }]
      : []),
    ...(permissions.includes('downloads.view')
      ? [{ title: "Downloads", url: route('admin.marketplace.downloads.index') }]
      : []),
  ];

  // Helper to determine if a route is active
  const isActive = (pattern: string) => {
    if (pattern === 'dashboard') return url === '/admin/dashboard';
    // Simple check for now, can be improved with regex
    return url.startsWith('/admin/' + pattern);
  };

  const data = {
    navMain: [
      {
        title: "Platform",
        url: route('admin.dashboard'),
        icon: LayoutDashboard,
        isActive: true,
        items: [
          { title: "Dashboard", url: route('admin.dashboard') },
        ],
      },
      {
        title: "Content",
        url: "#",
        icon: BookOpen,
        isActive: true,
        items: [
          { title: "Pages", url: route('admin.pages.index') },
          { title: "Menus", url: route('admin.menus.index') },
          { title: "Banners", url: route('admin.banners.index') },
          { title: "Vault", url: route('admin.vault.index') },
        ]
      },
      ...(marketplaceItems.length > 0
        ? [{
            title: "Marketplace",
            url: "#",
            icon: Factory,
            isActive: true,
            items: marketplaceItems,
          }]
        : []),
      {
        title: "Administration",
        url: "#",
        icon: Settings2,
        isActive: true,
        items: [
          { title: "Users", url: route('admin.users.index') },
          { title: "Roles", url: route('admin.roles.index') },
          { title: "AI Integrations", url: route('admin.ai-hubs.index') },
          { title: "Email Logs", url: route('admin.email-logs.index') },
          { title: "Activity", url: route('admin.activity-log.index') },
          { title: "Settings", url: route('admin.settings.index') },
        ]
      }
    ],
    navSecondary: [],
    projects: [] // Can be used for "Quick Access" later
  };

  const userData = {
    name: user.name,
    email: user.email,
    avatar: `https://ui-avatars.com/api/?name=${user.name}`,
  };

  return (
    <Sidebar collapsible="icon" {...props}>
      <SidebarHeader>
        <div className="flex items-center gap-2 px-2 py-2">
          <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
            <LayoutDashboard className="size-4" />
          </div>
          <div className="grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden">
            <span className="truncate font-semibold">{appName}</span>
            <span className="truncate text-xs">v0.2.0</span>
          </div>
        </div>
      </SidebarHeader>
      <SidebarContent>
        <NavMain items={data.navMain} />
        <NavSecondary items={data.navSecondary} className="mt-auto" />
      </SidebarContent>
      <SidebarFooter>
        <NavUser user={userData} />
      </SidebarFooter>
      <SidebarRail />
    </Sidebar>
  )
}
