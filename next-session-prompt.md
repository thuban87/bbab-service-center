# Next Session Handoff - BBAB Core Plugin

**Date:** December 24, 2024
**Context:** Continuing development of Brad's Workbench admin command center plugin
**Previous Session:** Completed Phase 3 sub-pages, fixing remaining issues, adding new dashboard boxes

---

## Current State Summary

We've built the core Workbench plugin with:
- Main dashboard at `admin.php?page=bbab-workbench` with 3 KPI boxes (Service Requests, Projects, Invoices)
- Three sub-pages with WP_List_Table: Projects, Service Requests, Invoices
- Each box has 3 buttons: Hub link, WP List link, New Item link
- Transient caching throughout
- Custom search that searches meta fields (not just post_title)
- Status filter pills, client dropdown filters

---

## Outstanding Bugs to Fix

### 1. Sortable Columns Not Working (SR and Projects)

**Problem:** Column headers appear clickable but clicking them doesn't actually sort the data.

**Files affected:**
- `admin/class-workbench-requests.php` - Priority and Hours columns
- `admin/class-workbench-projects.php` - Hours and Budget columns

**Root cause:** The columns are registered in `get_sortable_columns()` but the `prepare_items()` method doesn't actually handle the `orderby` and `order` query parameters. The sorting logic currently only does status-priority sorting, ignoring user-requested column sorts.

**Fix needed:** In `prepare_items()`, check for `$_GET['orderby']` and `$_GET['order']` parameters and apply appropriate sorting before or after the status-priority sort. For meta-based columns like hours/budget, you'll need to calculate values and sort the array manually since WP_Query can't sort by computed values.

### 2. "Add Time Entry" Row Action Not Pre-Populating SR Relationship

**Problem:** The "Add Time Entry" link in Service Requests sub-page takes you to a new Time Entry, but the related_service_request field isn't pre-populated. The TE creation page shows an error saying "you can only create a TE from a SR or PR".

**File affected:** `admin/class-workbench-requests.php` around line 479-486

**Current code:**
```php
'add_time_entry' => sprintf(
    '<a href="%s">%s</a>',
    esc_url( add_query_arg( array(
        'post_type'                 => 'time_entry',
        'related_service_request'   => $item->ID,
    ), admin_url( 'post-new.php' ) ) ),
    __( 'Add Time Entry', 'bbab-core' )
),
```

**Issue:** The URL parameter `related_service_request` isn't being picked up by Pods to pre-fill the relationship field. Need to investigate how Pods expects this parameter (might need a different param name or a hook to populate it).

**Note:** The same pattern in Projects (`related_project`) may have the same issue - needs testing.

---

## New Feature: Dashboard Expansion

### Overview

Add two new dashboard boxes below the existing three, with a visual section divider:

```
┌─────────────────────────────────────────────────────────────┐
│  Service Requests  │    Projects    │     Invoices          │
└─────────────────────────────────────────────────────────────┘
─────────────────── Section Divider ───────────────────────────
┌─────────────────────────────────────────────────────────────┐
│  Roadmap Items     │  Client Tasks  │    [Empty/Future]     │
└─────────────────────────────────────────────────────────────┘
```

### CPT Details

Both CPTs exist - check `Documentation/pods-package-*.json` for full field definitions.

### Client Tasks Box & Sub-Page

**Dashboard Box Columns:**
- task_description (truncated)
- due_date
- task_status
- client_organization (display organization_shortcode from related org)

**Sub-Page Requirements:**
- Summary stats bar (design based on what makes sense - probably status counts + overdue count)
- Status filter pills
- Client dropdown filter
- Search across all visible columns
- Table columns: task_description, due_date, task_status, client_organization, created_date, assigned_user
- Row actions: Edit, View related items as appropriate

### Roadmap Items Box & Sub-Page

**Dashboard Box Columns:**
- description (truncated)
- roadmap_status
- organization (display organization_shortcode)
- priority

**Sub-Page Requirements:**
- Summary stats bar (design based on what makes sense)
- Status filter pills
- Search across all visible columns
- Table columns: description, roadmap_status, organization, priority, submitted_by, adr_pdf, estimated_hours, roadmap_category, related_project (display as "PR# - project_name")
- Row actions: Edit, View related items as appropriate

### Implementation Pattern

Follow the exact same patterns as existing sub-pages:
1. Create `admin/class-workbench-tasks.php` with Workbench_Tasks class + Tasks_List_Table class
2. Create `admin/partials/workbench-tasks.php` template
3. Create `admin/class-workbench-roadmap.php` with Workbench_Roadmap class + Roadmap_List_Table class
4. Create `admin/partials/workbench-roadmap.php` template
5. Update `admin/class-workbench.php`:
   - Add properties for new page instances
   - Instantiate in constructor
   - Register submenu pages
   - Add query methods for dashboard boxes
6. Update `admin/partials/workbench-main.php`:
   - Add section divider after Invoices box
   - Add Roadmap Items box
   - Add Client Tasks box
7. Update `admin/class-admin.php`:
   - Add new page slugs to `is_plugin_page()` method

---

## Current Todo List State

```json
[
  {
    "content": "Fix SR search to include meta fields",
    "status": "completed",
    "activeForm": "Fixing SR search functionality"
  },
  {
    "content": "Make Priority and Hours sortable in SR table",
    "status": "completed",
    "activeForm": "Adding sortable columns to SR"
  },
  {
    "content": "Add 'Add Time Entry' row action to SR and Projects",
    "status": "completed",
    "activeForm": "Adding row actions"
  },
  {
    "content": "Make Hours and Budget sortable in Projects table",
    "status": "completed",
    "activeForm": "Adding sortable columns to Projects"
  },
  {
    "content": "Clarify Invoice stats bar labels",
    "status": "completed",
    "activeForm": "Clarifying invoice stats"
  },
  {
    "content": "Fix Invoice search to include related_to fields",
    "status": "completed",
    "activeForm": "Fixing invoice search"
  }
]
```

**Note:** Items 2 and 4 are marked completed but actually need additional work - the columns were added to `get_sortable_columns()` but the actual sorting logic in `prepare_items()` wasn't implemented.

---

## Suggested New Todo List

```
1. Fix sortable columns in SR table (Priority, Hours) - actually implement sorting logic
2. Fix sortable columns in Projects table (Hours, Budget) - actually implement sorting logic
3. Fix "Add Time Entry" row action to pre-populate SR/Project relationship
4. Add section divider to dashboard template
5. Create Client Tasks dashboard box + query methods
6. Create Client Tasks sub-page (class + template)
7. Create Roadmap Items dashboard box + query methods
8. Create Roadmap Items sub-page (class + template)
9. Register new sub-pages in Workbench class
10. Update Admin class for new page styles
```

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `bbab-core.php` | Main plugin bootstrap |
| `admin/class-workbench.php` | Main Workbench class, menu registration, dashboard queries |
| `admin/class-workbench-projects.php` | Projects sub-page + list table |
| `admin/class-workbench-requests.php` | Service Requests sub-page + list table |
| `admin/class-workbench-invoices.php` | Invoices sub-page + list table |
| `admin/class-admin.php` | Admin hooks, enqueue styles, pre_get_posts filters |
| `admin/class-cache.php` | Transient caching helper |
| `admin/partials/workbench-main.php` | Main dashboard template |
| `admin/partials/workbench-*.php` | Sub-page templates |
| `admin/css/workbench.css` | All custom styles |
| `Documentation/pods-package-*.json` | CPT field definitions |
| `CLAUDE.md` | Project instructions and coding standards |

---

## Important Coding Standards (from CLAUDE.md)

1. **Always use `get_posts()` with `foreach`** - NEVER use `WP_Query` with `the_post()` in admin context
2. **Namespace everything** under `BBAB\Core\`
3. **Escape all output** - `esc_html()`, `esc_attr()`, `esc_url()`
4. **Transient caching** - All expensive queries use WordPress transients
5. **Never run git commands** - Prompt Brad to run them manually

---

## Future Ideas Noted

- **Analytics/Metrics Dashboard:** Brad wants a GA4-style analytics view with historical data, graphs, charts for service center metrics. This would be a larger feature requiring an ADR.
- **Invoice Finalize Action:** Brad asked about adding a "Finalize" row action to invoices that triggers existing snippet workflow logic. Deferred for now.

---

## Starting Prompt for New Session

"I'm continuing development of the bbab-core plugin (Brad's Workbench). Please read the `next-session-prompt.md` file in the plugin root - it contains the full handoff from the previous session including bugs to fix and new features to build. Start by fixing the sortable columns bug, then the Add Time Entry pre-population issue, then proceed to building the new Client Tasks and Roadmap Items dashboard boxes and sub-pages."
