# Layout Builder Sidebar - Functional Testing Guide

This document provides comprehensive functional testing procedures for the Layout Builder sidebar state management functionality.

## Test Environment Setup

### Prerequisites
- Drupal site with Layout Builder enabled
- Gin admin theme active
- YS Layouts module enabled
- Test content type with Layout Builder enabled
- Admin user with appropriate permissions

### Required Permissions
- `configure any layout`
- `administer node display`
- `edit any page content`
- `create page content`
- `access administration pages`

## Manual Testing Procedures

### Test 1: Context Detection and CSS Classes

**Objective**: Verify that correct context classes are applied based on the interface.

#### Test Steps - Manage Settings Context
1. Navigate to `/node/[nid]/edit` (node edit form)
2. Inspect the `<body>` element
3. **Expected**: `body.ys-layout-manage-settings` class should be present
4. Inspect the sidebar element `#gin_sidebar`
5. **Expected**: `.ys-manage-settings` class should be present

#### Test Steps - Edit Layout Context
1. Navigate to `/node/[nid]/layout` (layout edit form)
2. Inspect the `<body>` element
3. **Expected**: `body.ys-layout-edit-layout` class should be present
4. Inspect the sidebar element `#gin_sidebar`
5. **Expected**: `.ys-edit-layout` class should be present

### Test 2: Gear Icon Visibility

**Objective**: Verify gear icon (sidebar toggle) visibility rules.

#### Test Steps - Manage Settings Interface
1. Navigate to `/node/[nid]/edit`
2. Look for gear icon (`.meta-sidebar__trigger`)
3. **Expected**: Gear icon should be completely hidden (not visible)
4. Try to click where the gear icon would be
5. **Expected**: No toggle functionality should occur

#### Test Steps - Edit Layout Interface
1. Navigate to `/node/[nid]/layout`
2. Look for gear icon (`.meta-sidebar__trigger`)
3. **Expected**: Gear icon should be visible and clickable
4. Click the gear icon
5. **Expected**: Sidebar should toggle open/closed

### Test 3: Default Sidebar States

**Objective**: Verify correct default states for each interface.

#### Test Steps - Manage Settings Defaults
1. Clear all localStorage keys starting with `YaleSites.layoutBuilder`
2. Navigate to `/node/[nid]/edit`
3. Open browser developer tools → Application/Storage → Local Storage
4. **Expected localStorage keys**:
   - `YaleSites.layoutBuilder.manageSettings.sidebarWidth` = `"360px"`
   - `YaleSites.layoutBuilder.manageSettings.sidebarExpanded.desktop` = `"true"`
   - `YaleSites.layoutBuilder.manageSettings.sidebarExpanded.mobile` = `"false"`
5. **Expected Visual State**: Sidebar should be open and 360px wide

#### Test Steps - Edit Layout Defaults
1. Clear all localStorage keys starting with `YaleSites.layoutBuilder`
2. Navigate to `/node/[nid]/layout`
3. Check localStorage keys
4. **Expected localStorage keys**:
   - `YaleSites.layoutBuilder.editLayout.sidebarWidth` = `"400px"`
   - `YaleSites.layoutBuilder.editLayout.sidebarExpanded.desktop` = `"false"`
   - `YaleSites.layoutBuilder.editLayout.sidebarExpanded.mobile` = `"false"`
5. **Expected Visual State**: Sidebar should be closed by default

### Test 4: Width Persistence

**Objective**: Test that width changes persist across page reloads.

#### Test Steps - Manage Settings Width Persistence
1. Navigate to `/node/[nid]/edit`
2. Resize sidebar by dragging the handle to a different width (e.g., 450px)
3. Reload the page
4. **Expected**: Sidebar should maintain the 450px width
5. Check localStorage: `YaleSites.layoutBuilder.manageSettings.sidebarWidth` should be `"450px"`

#### Test Steps - Edit Layout Width Persistence
1. Navigate to `/node/[nid]/layout`
2. Open the sidebar (if closed) using the gear icon
3. Resize sidebar by dragging to a different width (e.g., 500px)
4. Reload the page
5. **Expected**: Sidebar should maintain the 500px width
6. Check localStorage: `YaleSites.layoutBuilder.editLayout.sidebarWidth` should be `"500px"`

### Test 5: Context Isolation

**Objective**: Verify that changes in one context don't affect the other.

#### Test Steps
1. Navigate to `/node/[nid]/layout` (Edit Layout)
2. Open sidebar and resize to 560px (maximum width)
3. Navigate to `/node/[nid]/edit` (Manage Settings)
4. **Expected**: Sidebar should still be 360px (or previously set width)
5. Navigate back to `/node/[nid]/layout`
6. **Expected**: Sidebar should still be 560px
7. **Verify localStorage**: Both contexts should maintain separate width values

### Test 6: Sidebar Toggle Behavior

**Objective**: Test sidebar show/hide functionality per context.

#### Test Steps - Manage Settings (Always Open)
1. Navigate to `/node/[nid]/edit`
2. **Expected**: Sidebar should be open and no gear icon visible
3. Try JavaScript console: `Drupal.ginSidebar.collapseSidebar()`
4. **Expected**: Sidebar should remain open (function should be overridden)

#### Test Steps - Edit Layout (Normal Toggle)
1. Navigate to `/node/[nid]/layout`
2. Click gear icon to toggle sidebar closed
3. **Expected**: Sidebar should close
4. Click gear icon again
5. **Expected**: Sidebar should open
6. **Verify localStorage**: Expanded state should change accordingly

### Test 7: Breakpoint Handling

**Objective**: Test responsive behavior across different screen sizes.

#### Test Steps - Desktop Breakpoint (≥1024px)
1. Set browser width to 1200px
2. Navigate to `/node/[nid]/edit`
3. **Expected**: Desktop expanded state should be used
4. Check localStorage: `YaleSites.layoutBuilder.manageSettings.sidebarExpanded.desktop`

#### Test Steps - Mobile Breakpoint (<1024px)
1. Set browser width to 800px
2. Navigate to `/node/[nid]/edit`
3. **Expected**: Mobile expanded state should be used
4. Check localStorage: `YaleSites.layoutBuilder.manageSettings.sidebarExpanded.mobile`

#### Test Steps - Breakpoint Transition
1. Start at desktop width (1200px) with sidebar open
2. Resize to mobile width (800px)
3. **Expected**: Sidebar state should adapt to mobile settings
4. Resize back to desktop width
5. **Expected**: Desktop settings should be restored

### Test 8: Width Migration

**Objective**: Test migration of old unitless width values to pixel values.

#### Test Steps
1. Using browser console, set old format width:
   ```javascript
   localStorage.setItem('YaleSites.layoutBuilder.manageSettings.sidebarWidth', '360');
   ```
2. Navigate to `/node/[nid]/edit`
3. Wait for page load and JavaScript initialization
4. Check localStorage: `YaleSites.layoutBuilder.manageSettings.sidebarWidth`
5. **Expected**: Value should be migrated to `"360px"` (with px unit)

### Test 9: Gin Integration

**Objective**: Verify proper integration with Gin theme's localStorage.

#### Test Steps
1. Navigate to `/node/[nid]/edit`
2. Check that Gin's native keys are properly set:
   - `Drupal.gin.sidebarWidth`
   - `Drupal.gin.sidebarExpanded.desktop`
   - `Drupal.gin.sidebarExpanded.mobile`
3. **Expected**: These should match the context-specific values
4. Check CSS custom property:
   ```javascript
   getComputedStyle(document.documentElement).getPropertyValue('--gin-sidebar-width');
   ```
5. **Expected**: Should match the stored width value

### Test 10: Real-time Drag Sync

**Objective**: Test that width changes during drag are immediately synced.

#### Test Steps - Edit Layout Only
1. Navigate to `/node/[nid]/layout`
2. Open sidebar if closed
3. Start dragging the resize handle
4. During drag, monitor localStorage using browser dev tools
5. Release drag at a new width
6. **Expected**: `YaleSites.layoutBuilder.editLayout.sidebarWidth` should update within 50ms
7. **Expected**: Gin's `Drupal.gin.sidebarWidth` should also be updated

## Automated Test Execution

### Running PHPUnit Tests
```bash
# Run all ys_layouts tests
lando phpunit --group ys_layouts

# Run specific test class
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_layouts/tests/src/FunctionalJavascript/LayoutBuilderSidebarTest.php

# Run with coverage
lando phpunit --coverage-html coverage --group ys_layouts
```

### Test Data Cleanup
After each test run, verify localStorage is properly cleaned:
```javascript
// Clear all YaleSites keys
Object.keys(localStorage).forEach(key => {
  if (key.startsWith('YaleSites.layoutBuilder')) {
    localStorage.removeItem(key);
  }
});
```

## Browser-Specific Testing Notes

### Chrome/Chromium
- localStorage works reliably
- DevTools provides excellent localStorage inspection
- Drag events work consistently

### Firefox
- localStorage behavior is consistent with Chrome
- DevTools localStorage inspection is slightly different UI
- Drag events work reliably

### Safari
- localStorage may have stricter security policies
- Test in private browsing mode for localStorage restrictions
- Drag events generally work but test thoroughly

### Edge
- Modern Edge (Chromium-based) behaves like Chrome
- localStorage works consistently
- Legacy Edge may have different behavior (not officially supported)

## Common Issues and Troubleshooting

### Issue: Sidebar width not persisting
**Diagnosis**: Check localStorage keys and values
**Solution**: Verify drag event listeners are properly attached

### Issue: Context classes not applied
**Diagnosis**: Check JavaScript console for errors
**Solution**: Verify Drupal.behaviors are properly attached

### Issue: Gear icon visibility issues
**Diagnosis**: Check CSS specificity and body classes
**Solution**: Verify CSS selectors match expected DOM structure

### Issue: Width migration not working
**Diagnosis**: Check for JavaScript errors during initialization
**Solution**: Verify CONFIG.timeouts values allow sufficient processing time

## Performance Considerations

### localStorage Usage
- Monitor localStorage size (should remain minimal)
- Keys should be cleaned up when no longer needed
- Verify no memory leaks in event listeners

### DOM Manipulation
- CSS changes should not cause layout thrashing
- Resize events should be debounced
- Force reflow operations should be minimized

## Accessibility Testing

### Keyboard Navigation
- Sidebar toggle should be keyboard accessible
- Drag handles should work with keyboard (if supported by Gin)
- Focus states should be preserved during state changes

### Screen Reader Compatibility
- State changes should be announced appropriately
- Hidden elements should not be accessible to screen readers
- ARIA attributes should be preserved during DOM manipulation

## Documentation Maintenance

This document should be updated when:
- New features are added to the sidebar functionality
- Browser support requirements change
- Test procedures need modification
- New edge cases are discovered

Last updated: Current implementation date
Version: Matches ys_layouts module version