# CAS Protection Confirmation Modal Tests

This directory contains comprehensive tests for the CAS protection confirmation modal functionality.

## Test Coverage

### Unit Tests (`Unit/CasProtectionModalTest.php`)
Tests the core modal logic and configuration:
- ✅ Modal content includes required data security messaging
- ✅ Modal content uses plain language (no technical jargon)
- ✅ Modal button configuration (Cancel/Confirm buttons)
- ✅ Modal configuration options (title, width, accessibility)
- ✅ Modal trigger conditions (enable/disable state changes)
- ✅ Modal accessibility attributes (ARIA, role, etc.)

### Functional Tests (`Functional/CasProtectionFormTest.php`)
Tests form integration and permissions:
- ✅ CAS protection field exists on node edit forms
- ✅ Field appears in Publishing Settings group
- ✅ Field has correct default value (disabled)
- ✅ Field saves correctly when enabled/disabled
- ✅ Proper permission checking for field access
- ✅ Field availability on different content types (page, post, event)
- ✅ Form validation works with CAS protection field
- ✅ Field has proper accessibility attributes and labeling
- ✅ Field works correctly with different user roles

### JavaScript Functional Tests (`FunctionalJavascript/CasProtectionModalJavascriptTest.php`)
Tests modal interactions and browser behavior:
- ✅ Modal appears when enabling CAS protection
- ✅ Modal appears when disabling CAS protection
- ✅ Cancel button reverts checkbox state and closes modal
- ✅ Confirm button allows form submission
- ✅ Keyboard accessibility (focus management, tab navigation)
- ✅ Escape key behavior (modal stays open, requires explicit action)
- ✅ ARIA attributes for screen reader support
- ✅ Form submission prevention until modal is confirmed
- ✅ Modal behavior with multiple checkbox toggles
- ✅ Modal styling and visual appearance

## Test Requirements Validation

### Acceptance Criteria Coverage
- ✅ Modal appears when user clicks CAS protection toggle (both enabling and disabling)
- ✅ Modal includes clear messaging that YaleSites is intended for low-risk data only
- ✅ Modal reminds users that sensitive information should not be published
- ✅ Modal includes "Cancel" and "Confirm" buttons with clear labeling
- ✅ User must explicitly confirm action before toggle state changes
- ✅ Modal design follows existing Emergency Site Alert modal patterns
- ✅ Modal content is concise and uses plain language
- ✅ Modal is keyboard accessible and screen reader friendly
- ✅ WCAG 2.1 AA compliance testing included

### Security and Data Protection
- ✅ Tests verify data security messaging is present
- ✅ Tests ensure plain language warnings about sensitive information
- ✅ Tests validate that users must explicitly confirm potentially risky actions

### Accessibility (WCAG 2.1 AA)
- ✅ Modal focus management and focus trapping
- ✅ Proper ARIA attributes (role="dialog", aria-modal, aria-label)
- ✅ Keyboard navigation support (Tab, Enter, Escape handling)
- ✅ Screen reader announcements and descriptions
- ✅ Button accessibility and clear labeling
- ✅ Form element accessibility

## Running the Tests

### Unit Tests
```bash
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_node_access/tests/src/Unit/
```

### Functional Tests
```bash
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_node_access/tests/src/Functional/
```

### JavaScript Tests
```bash
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_node_access/tests/src/FunctionalJavascript/
```

### All Tests
```bash
lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_node_access/tests/
```

## Test Dependencies

The tests require the following modules to be enabled:
- `node` - Core node functionality
- `field` - Field API
- `ys_node_access` - The module being tested
- `user` - User management and permissions

## Next Steps

These tests are designed to validate the CAS protection confirmation modal functionality before implementation. Once the actual modal is implemented:

1. Run the Unit tests to verify core logic
2. Run the Functional tests to verify form integration
3. Run the JavaScript tests to verify modal behavior
4. All tests should pass, confirming the implementation meets requirements

The tests will fail initially (as expected) since the modal functionality hasn't been implemented yet. They serve as a specification for the expected behavior and will guide the implementation process.