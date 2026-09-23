import * as React from "react"
import {
  Factory,
  LayoutDashboard,
  Settings2,
} from "lucide-react"

import { NavMain } from "@/Components/nav-main"
import { NavUser } from "@/Components/nav-user"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarRail,
} from "@/Components/ui/sidebar"
import { usePage } from "@inertiajs/react"

import { NavSecondary } from "@/Components/nav-secondary"

export function AppSidebar({ ...props }: React.ComponentProps<typeof Sidebar>) {
  const { appName, appVersion } = usePage().props;
  const user = usePage().props.auth.user;
  const permissions = usePage().props.auth.permissions ?? [];

  // Marketplace entries appear only for the matching `<resource>.view` permission.
  const marketplaceItems = [
    ...(permissions.includes('customers.view')
      ? [{ title: "Customers", url: route('admin.marketplace.customers.index') }]
      : []),
    ...(permissions.includes('scripts.view')
      ? [{ title: "Scripts", url: route('admin.marketplace.scripts.index') }]
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
    ...(permissions.includes('unysis_boxes.view')
      ? [{ title: "UNYSIS Boxes", url: route('admin.marketplace.unysis-boxes.index') }]
      : []),
    ...(permissions.includes('downloads.view')
      ? [
          { title: "Downloads", url: route('admin.marketplace.downloads.index') },
          { title: "Usage Report", url: route('admin.marketplace.reports.usage') },
        ]
      : []),
  ];

  const administrationItems = [
    ...(permissions.includes('media.view')
      ? [{ title: "Vault", url: route('admin.vault.index') }]
      : []),
    ...(permissions.includes('users.view')
      ? [{ title: "Users", url: route('admin.users.index') }]
      : []),
    ...(permissions.includes('roles.view')
      ? [{ title: "Roles", url: route('admin.roles.index') }]
      : []),
    ...(permissions.includes('email_logs.view')
      ? [{ title: "Email Logs", url: route('admin.email-logs.index') }]
      : []),
    { title: "Activity", url: route('admin.activity-log.index') },
    ...(permissions.includes('manage-settings')
      ? [{ title: "Settings", url: route('admin.settings.index') }]
      : []),
  ];

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
        items: administrationItems,
      }
    ],
    navSecondary: [],
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
            <Factory className="size-4" />
          </div>
          <div className="grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden">
            <span className="truncate font-semibold">{appName}</span>
            <span className="truncate text-xs">v{appVersion}</span>
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
