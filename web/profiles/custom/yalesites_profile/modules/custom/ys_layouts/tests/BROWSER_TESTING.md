# Layout Builder Sidebar - Browser Testing Procedures

This document provides detailed browser-specific testing procedures and compatibility information for the Layout Builder sidebar functionality.

## Supported Browsers

### Primary Support (Fully Tested)
- **Chrome/Chromium** 90+ (Desktop & Mobile)
- **Firefox** 88+ (Desktop & Mobile)  
- **Safari** 14+ (Desktop & Mobile)
- **Edge** 90+ (Chromium-based)

### Secondary Support (Basic Testing)
- **Opera** 76+ (Chromium-based)
- **Samsung Internet** 14+
- **Mobile Safari** (iOS 14+)

### Not Supported
- **Internet Explorer** (any version)
- **Legacy Edge** (EdgeHTML engine)

## Browser-Specific Testing Guidelines

### Chrome/Chromium Testing

**Strengths:**
- Most reliable localStorage implementation
- Excellent DevTools for debugging
- Consistent drag/drop behavior
- Best monitoring/debugging experience

**Testing Checklist:**
- [ ] localStorage persistence across tabs
- [ ] Sidebar resize functionality via drag
- [ ] Context switching behavior
- [ ] DevTools localStorage inspection
- [ ] Console monitoring output
- [ ] Performance profiling

**Known Issues:**
- None currently identified

**Testing Commands:**
```javascript
// Enable console logging for debugging
Drupal.ysLayoutsMonitor.setConsoleLogging(true);

// Check storage usage
console.log(Drupal.ysLayoutsMonitor.getStats());

// Generate full report
console.log(Drupal.ysLayoutsMonitor.generateReport());
```

### Firefox Testing

**Strengths:**
- Reliable localStorage implementation
- Good drag/drop support
- Strong privacy features

**Testing Checklist:**
- [ ] localStorage behavior in private browsing
- [ ] Sidebar drag functionality
- [ ] Context isolation
- [ ] DevTools storage inspection
- [ ] Enhanced tracking protection impact
- [ ] Cross-tab synchronization

**Known Issues:**
- DevTools localStorage UI slightly different from Chrome
- May require page refresh after clearing localStorage

**Firefox-Specific Tests:**
```javascript
// Test private browsing compatibility
// 1. Open private browsing window
// 2. Navigate to layout builder
// 3. Verify localStorage works
// 4. Check persistence within session

// Test Enhanced Tracking Protection
// 1. Enable strict protection
// 2. Verify sidebar functionality unchanged
// 3. Check for console warnings
```

**DevTools Notes:**
- Storage tab: `F12 → Storage → Local Storage`
- Console access: `F12 → Console`
- Different UI layout from Chrome DevTools

### Safari Testing

**Strengths:**
- Good standards compliance
- Reliable on iOS devices

**Testing Checklist:**
- [ ] localStorage in private browsing mode
- [ ] iOS Safari compatibility
- [ ] Touch-based drag functionality
- [ ] Desktop Safari drag behavior
- [ ] Storage quota limitations
- [ ] Cross-device synchronization (if applicable)

**Known Issues:**
- Private browsing may have localStorage restrictions
- iOS Safari may have different touch event handling
- Storage quota more restrictive than other browsers

**Safari-Specific Tests:**
```javascript
// Test storage quota limits
function testStorageQuota() {
  try {
    const testKey = 'YaleSites.test.quota';
    const testValue = 'x'.repeat(1024 * 1024); // 1MB string
    localStorage.setItem(testKey, testValue);
    localStorage.removeItem(testKey);
    console.log('Storage quota: OK');
  } catch (e) {
    console.log('Storage quota exceeded:', e);
  }
}
```

**iOS Safari Notes:**
- Test on actual devices when possible
- Verify touch-based resize functionality
- Check viewport scaling behavior
- Test rotation handling

### Edge (Chromium) Testing

**Strengths:**
- Similar to Chrome (Chromium-based)
- Good enterprise environment support

**Testing Checklist:**
- [ ] localStorage functionality
- [ ] Corporate proxy compatibility
- [ ] Enterprise security settings
- [ ] Sidebar drag behavior
- [ ] Context switching
- [ ] DevTools functionality

**Known Issues:**
- None currently identified (behaves like Chrome)

**Edge-Specific Considerations:**
- May have different security policies in enterprise environments
- Test with various enterprise configurations
- Verify Group Policy doesn't interfere

## Cross-Browser Testing Matrix

| Feature | Chrome | Firefox | Safari | Edge | Notes |
|---------|--------|---------|--------|------|-------|
| localStorage | ✅ | ✅ | ⚠️ | ✅ | Safari: Limited in private browsing |
| Drag Resize | ✅ | ✅ | ✅ | ✅ | All browsers support |
| Context Detection | ✅ | ✅ | ✅ | ✅ | URL/DOM based detection |
| Monitoring API | ✅ | ✅ | ✅ | ✅ | Global Drupal.ysLayoutsMonitor |
| DevTools Support | ✅ | ⚠️ | ⚠️ | ✅ | Chrome/Edge best experience |
| Mobile Support | ✅ | ✅ | ✅ | N/A | Edge mobile discontinued |

## Mobile Browser Testing

### Mobile Chrome (Android)
**Testing Checklist:**
- [ ] Touch-based drag functionality
- [ ] Responsive breakpoint behavior
- [ ] localStorage persistence
- [ ] Context switching on mobile URLs

**Mobile-Specific Tests:**
```javascript
// Test mobile breakpoint detection
function testMobileBreakpoint() {
  console.log('Window width:', window.innerWidth);
  console.log('Is desktop breakpoint:', window.innerWidth >= 1024);
  
  // Force different widths for testing
  Object.defineProperty(window, 'innerWidth', {
    value: 800,
    configurable: true
  });
  // Test mobile behavior
}
```

### Mobile Safari (iOS)
**Testing Checklist:**
- [ ] Touch events for resize
- [ ] Viewport meta tag behavior
- [ ] localStorage in iOS Safari
- [ ] Context detection on mobile

### Mobile Testing Notes
- Always test on actual devices
- Verify touch events work properly
- Check responsive behavior at various screen sizes
- Test rotation from portrait to landscape

## localStorage Testing Procedures

### Basic localStorage Tests
```javascript
// Test basic functionality
function testBasicStorage() {
  const testKey = 'YaleSites.test.basic';
  const testValue = 'test-value-123';
  
  // Set
  localStorage.setItem(testKey, testValue);
  console.log('Set:', testKey, '=', testValue);
  
  // Get
  const retrieved = localStorage.getItem(testKey);
  console.log('Retrieved:', retrieved);
  console.log('Match:', retrieved === testValue);
  
  // Clean up
  localStorage.removeItem(testKey);
}
```

### Storage Persistence Tests
```javascript
// Test persistence across page reloads
function testStoragePersistence() {
  const testKey = 'YaleSites.test.persistence';
  const testValue = 'persist-' + Date.now();
  
  localStorage.setItem(testKey, testValue);
  console.log('Stored for persistence test:', testValue);
  console.log('Please reload the page and run: localStorage.getItem("' + testKey + '")');
}
```

### Cross-Tab Storage Tests
```javascript
// Test storage visibility across tabs
function testCrossTabStorage() {
  const testKey = 'YaleSites.test.crossTab';
  const testValue = 'crosstab-' + Date.now();
  
  localStorage.setItem(testKey, testValue);
  console.log('Stored for cross-tab test:', testValue);
  console.log('Open a new tab and check: localStorage.getItem("' + testKey + '")');
}
```

## Performance Testing

### Storage Performance Tests
```javascript
// Test storage operation performance
function testStoragePerformance() {
  const iterations = 1000;
  const testKey = 'YaleSites.test.performance';
  
  // Test writes
  console.time('Storage Writes');
  for (let i = 0; i < iterations; i++) {
    localStorage.setItem(testKey + i, 'value' + i);
  }
  console.timeEnd('Storage Writes');
  
  // Test reads
  console.time('Storage Reads');
  for (let i = 0; i < iterations; i++) {
    localStorage.getItem(testKey + i);
  }
  console.timeEnd('Storage Reads');
  
  // Clean up
  for (let i = 0; i < iterations; i++) {
    localStorage.removeItem(testKey + i);
  }
}
```

### Monitoring Performance Tests
```javascript
// Test monitoring overhead
function testMonitoringPerformance() {
  Drupal.ysLayoutsMonitor.clearLog();
  
  console.time('Monitored Operations');
  for (let i = 0; i < 100; i++) {
    const key = 'YaleSites.layoutBuilder.test.perf' + i;
    localStorage.setItem(key, 'value' + i);
    localStorage.getItem(key);
    localStorage.removeItem(key);
  }
  console.timeEnd('Monitored Operations');
  
  console.log('Log entries:', Drupal.ysLayoutsMonitor.getStats().totalOperations);
}
```

## DevTools Usage by Browser

### Chrome DevTools
**Accessing localStorage:**
1. F12 → Application tab → Storage → Local Storage
2. Or Console: `localStorage`

**Useful Console Commands:**
```javascript
// View all YaleSites keys
Object.keys(localStorage).filter(k => k.startsWith('YaleSites'))

// Clear YaleSites keys only
Object.keys(localStorage).forEach(k => {
  if (k.startsWith('YaleSites')) localStorage.removeItem(k);
});

// Monitor storage events
window.addEventListener('storage', (e) => {
  console.log('Storage changed:', e.key, e.oldValue, e.newValue);
});
```

### Firefox DevTools
**Accessing localStorage:**
1. F12 → Storage tab → Local Storage
2. Or Console: `localStorage`

**Firefox-Specific:**
- Storage tab may require refresh to show changes
- Console.table() works well for viewing storage objects

### Safari DevTools
**Accessing localStorage:**
1. Develop menu → Show Web Inspector → Storage
2. Or Console: `localStorage`

**Safari Notes:**
- Must enable Developer tools first
- Storage inspection less detailed than Chrome/Firefox

## Automated Browser Testing

### Running Tests Across Browsers
```bash
# Run functional JavaScript tests
lando phpunit --group ys_layouts

# Run specific browser tests (if configured)
lando phpunit tests/src/FunctionalJavascript/LayoutBuilderSidebarTest.php
```

### Browser Test Configuration
The PHPUnit tests can be configured to run against different browsers by setting the `MINK_DRIVER_ARGS_WEBDRIVER` environment variable:

```bash
# Chrome (default)
export MINK_DRIVER_ARGS_WEBDRIVER='["chrome"]'

# Firefox
export MINK_DRIVER_ARGS_WEBDRIVER='["firefox"]'

# Headless Chrome
export MINK_DRIVER_ARGS_WEBDRIVER='["chrome", {"chromeOptions": {"args": ["--headless"]}}]'
```

## Troubleshooting by Browser

### Chrome Issues
**Problem:** Console showing localStorage errors
**Solution:** Check for extension interference, test in incognito mode

**Problem:** Drag functionality not working
**Solution:** Verify CSS isn't interfering with pointer events

### Firefox Issues  
**Problem:** localStorage not persisting
**Solution:** Check Enhanced Tracking Protection settings

**Problem:** Different behavior in private browsing
**Solution:** Expected - private browsing has storage limitations

### Safari Issues
**Problem:** localStorage quota exceeded
**Solution:** Safari has more restrictive limits, check data size

**Problem:** Drag not working on iOS
**Solution:** Verify touch events are properly handled

### Edge Issues
**Problem:** Enterprise restrictions
**Solution:** Check Group Policy settings for localStorage permissions

## Manual Testing Checklist

### Before Testing
- [ ] Clear all localStorage data
- [ ] Close other tabs/windows
- [ ] Disable browser extensions (if testing in isolation)
- [ ] Enable DevTools and console logging

### During Testing
- [ ] Monitor console for errors
- [ ] Check localStorage values in DevTools
- [ ] Verify visual sidebar behavior matches expected
- [ ] Test context switching between interfaces
- [ ] Verify persistence across page reloads

### After Testing
- [ ] Export monitoring data if needed
- [ ] Document any browser-specific issues
- [ ] Clear test data
- [ ] Reset browser to normal state

## Reporting Browser Issues

When reporting browser-specific issues, include:

1. **Browser and version** (from `navigator.userAgent`)
2. **Operating system** 
3. **Steps to reproduce**
4. **Expected vs actual behavior**
5. **Console errors** (if any)
6. **Monitoring report** (`Drupal.ysLayoutsMonitor.generateReport()`)
7. **localStorage contents** before and after issue

### Example Issue Report
```
Browser: Chrome 96.0.4664.110 (Windows 10)
Issue: Sidebar width not persisting on reload
Steps:
1. Navigate to /node/123/edit
2. Resize sidebar to 500px
3. Reload page
Expected: Sidebar remains 500px
Actual: Sidebar resets to 360px
Console errors: None
localStorage keys: [attach monitoring report]
```

This comprehensive browser testing guide ensures consistent functionality across all supported browsers and provides clear procedures for identifying and resolving browser-specific issues.