import { Link, usePage, router } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useState } from 'react';
import {
  LayoutDashboard,
  Receipt,
  CreditCard,
  PiggyBank,
  FileText,
  Link2,
  Settings,
  HelpCircle,
  Bell,
  LogOut,
  ChevronLeft,
  ChevronRight,
  Menu,
  X,
} from 'lucide-react';

interface NavItemDef {
  label: string;
  href: string;
  routeName: string;
  icon: ReactNode;
  badge?: number;
}

function NavItem({
  item,
  active,
  collapsed,
}: {
  item: NavItemDef;
  active: boolean;
  collapsed: boolean;
}) {
  return (
    <Link
      href={item.href}
      className={`relative flex items-center gap-3 w-full px-4 py-2.5 rounded-lg text-sm font-medium transition-colors ${
        active
          ? 'bg-sw-accent/10 text-sw-accent border-l-2 border-sw-accent'
          : 'text-sw-muted hover:text-sw-text hover:bg-sw-card border-l-2 border-transparent'
      }`}
    >
      <span className="shrink-0">{item.icon}</span>
      {!collapsed && <span>{item.label}</span>}
      {!collapsed && item.badge !== undefined && item.badge > 0 && (
        <span className="ml-auto bg-sw-danger text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center">
          {item.badge}
        </span>
      )}
    </Link>
  );
}

export default function AuthenticatedLayout({
  header,
  children,
}: PropsWithChildren<{ header?: ReactNode }>) {
  const page = usePage();
  const user = page.props.auth.user as { name: string; email: string };
  const currentRoute = (page.props as Record<string, unknown>).ziggy
    ? route().current()
    : '';

  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);

  const navItems: NavItemDef[] = [
    { label: 'Dashboard', href: '/dashboard', routeName: 'dashboard', icon: <LayoutDashboard size={18} /> },
    { label: 'Transactions', href: '/transactions', routeName: 'transactions', icon: <Receipt size={18} /> },
    { label: 'Subscriptions', href: '/subscriptions', routeName: 'subscriptions', icon: <CreditCard size={18} /> },
    { label: 'Savings', href: '/savings', routeName: 'savings', icon: <PiggyBank size={18} /> },
    { label: 'Tax', href: '/tax', routeName: 'tax', icon: <FileText size={18} /> },
    { label: 'Connect', href: '/connect', routeName: 'connect', icon: <Link2 size={18} /> },
    { label: 'Settings', href: '/settings', routeName: 'settings', icon: <Settings size={18} /> },
    { label: 'AI Questions', href: '/questions', routeName: 'questions', icon: <HelpCircle size={18} /> },
  ];

  const isActive = (routeName: string) => {
    try {
      return route().current(routeName);
    } catch {
      return currentRoute === routeName;
    }
  };

  const sidebarContent = (
    <>
      {/* Logo */}
      <div className="flex items-center gap-3 px-5 py-5 border-b border-sw-border">
        <Link href="/dashboard" className="flex items-center gap-2">
          <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-9 w-9 shrink-0">
            <rect width="40" height="40" rx="10" fill="url(#sidebar-logo-gradient)" />
            <path d="M8 28.5 Q20 31 32 28.5" stroke="white" strokeWidth="2.2" strokeLinecap="round" fill="none" />
            <rect x="12.5" y="22" width="3.5" height="6.5" rx="1.5" fill="white" fillOpacity="0.55" />
            <rect x="18" y="17" width="3.5" height="11.5" rx="1.5" fill="white" fillOpacity="0.75" />
            <rect x="23.5" y="12" width="3.5" height="16.5" rx="1.5" fill="white" />
            <circle cx="25.25" cy="9.5" r="1.6" fill="white" fillOpacity="0.9" />
            <line x1="25.25" y1="6" x2="25.25" y2="7.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.7" />
            <line x1="22.5" y1="9.5" x2="23.2" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.7" />
            <line x1="27.3" y1="9.5" x2="28" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.7" />
            <defs>
              <linearGradient id="sidebar-logo-gradient" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
                <stop stopColor="#2563eb" />
                <stop offset="1" stopColor="#7c3aed" />
              </linearGradient>
            </defs>
          </svg>
          {!collapsed && (
            <div>
              <div className="text-[15px] font-bold text-sw-text tracking-tight">Ledger<span className="text-sw-accent">IQ</span></div>
              <div className="text-[10px] text-sw-dim font-medium tracking-wide">AI-Powered Financial Intelligence</div>
            </div>
          )}
        </Link>
      </div>

      {/* Nav */}
      <nav aria-label="Main navigation" className="flex-1 flex flex-col gap-1 px-3 py-4 overflow-y-auto">
        {navItems.map((item) => (
          <NavItem
            key={item.routeName}
            item={item}
            active={!!isActive(item.routeName)}
            collapsed={collapsed}
          />
        ))}
      </nav>

      {/* Collapse toggle (desktop only) */}
      <div className="hidden sm:block border-t border-sw-border p-3">
        <button
          onClick={() => setCollapsed(!collapsed)}
          className="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-sw-border bg-transparent text-sw-dim text-xs cursor-pointer hover:text-sw-muted transition"
        >
          {collapsed ? <ChevronRight size={14} /> : <><ChevronLeft size={14} /> Collapse</>}
        </button>
      </div>
    </>
  );

  return (
    <>
    <a href="#main-content" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:top-4 focus:left-4 focus:px-4 focus:py-2 focus:bg-sw-accent focus:text-white focus:rounded-lg focus:text-sm focus:font-semibold">Skip to main content</a>
    <div className="flex h-screen bg-sw-bg overflow-hidden">
      {/* Desktop sidebar */}
      <aside
        className={`hidden sm:flex flex-col bg-sw-sidebar border-r border-sw-border shrink-0 transition-all duration-300 ${
          collapsed ? 'w-[68px]' : 'w-64'
        }`}
      >
        {sidebarContent}
      </aside>

      {/* Mobile sidebar overlay */}
      {mobileOpen && (
        <div className="sm:hidden fixed inset-0 z-40">
          <div className="absolute inset-0 bg-black/20" onClick={() => setMobileOpen(false)} />
          <aside className="relative w-64 h-full bg-sw-sidebar border-r border-sw-border flex flex-col z-50">
            <button
              onClick={() => setMobileOpen(false)}
              aria-label="Close sidebar"
              className="absolute top-4 right-4 text-sw-muted hover:text-sw-text"
            >
              <X size={20} />
            </button>
            {sidebarContent}
          </aside>
        </div>
      )}

      {/* Main content area */}
      <div className="flex-1 flex flex-col overflow-hidden">
        {/* Top bar */}
        <header className="shrink-0 h-16 flex items-center justify-between px-6 border-b border-sw-border bg-sw-card shadow-sm">
          <div className="flex items-center gap-4">
            {/* Mobile hamburger */}
            <button
              onClick={() => setMobileOpen(true)}
              aria-label="Open sidebar"
              aria-expanded={mobileOpen}
              className="sm:hidden text-sw-muted hover:text-sw-text"
            >
              <Menu size={20} />
            </button>

            {header && <div>{header}</div>}
          </div>

          <div className="flex items-center gap-3">
            {/* Notification bell */}
            <button aria-label="Notifications" className="relative w-9 h-9 rounded-lg border border-sw-border bg-transparent flex items-center justify-center text-sw-muted hover:text-sw-text transition">
              <Bell size={16} />
            </button>

            {/* User dropdown */}
            <div className="relative">
              <button
                onClick={() => setUserMenuOpen(!userMenuOpen)}
                aria-expanded={userMenuOpen}
                aria-haspopup="true"
                className="w-9 h-9 rounded-lg bg-gradient-to-br from-purple-500 to-blue-500 flex items-center justify-center text-white text-sm font-bold cursor-pointer"
              >
                {user.name?.charAt(0)?.toUpperCase() || 'U'}
              </button>

              {userMenuOpen && (
                <>
                  <div className="fixed inset-0 z-30" onClick={() => setUserMenuOpen(false)} />
                  <div role="menu" className="absolute right-0 mt-2 w-48 rounded-xl border border-sw-border bg-sw-card shadow-lg z-40 py-1">
                    <div className="px-4 py-2 border-b border-sw-border">
                      <div className="text-sm font-medium text-sw-text truncate">{user.name}</div>
                      <div className="text-xs text-sw-dim truncate">{user.email}</div>
                    </div>
                    <Link
                      href="/settings"
                      role="menuitem"
                      className="flex items-center gap-2 px-4 py-2 text-sm text-sw-muted hover:text-sw-text hover:bg-sw-card-hover transition"
                    >
                      <Settings size={14} /> Settings
                    </Link>
                    <button
                      onClick={() => router.post('/logout')}
                      role="menuitem"
                      className="flex items-center gap-2 w-full px-4 py-2 text-sm text-sw-muted hover:text-sw-danger hover:bg-sw-card-hover transition"
                    >
                      <LogOut size={14} /> Log Out
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </header>

        {/* Page content */}
        <main id="main-content" role="main" className="flex-1 overflow-y-auto p-6">
          {children}
        </main>
      </div>
    </div>
    </>
  );
}
