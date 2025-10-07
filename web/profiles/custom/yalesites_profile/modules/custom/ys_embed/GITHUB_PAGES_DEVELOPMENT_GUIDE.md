# YaleSites GitHub Pages Application Development Guide

**Last Updated:** October 2025

This guide provides comprehensive instructions for developing applications that can be embedded in YaleSites using GitHub Pages and the `ys_embed` module's GitHub Applet embed source.

**Important Note:** This guide reflects current best practices as of December 2024. Build tools and syntax may evolve over time. Always verify configurations with the latest official documentation for each tool.

## About the ys_embed Module

The `ys_embed` module provides a sophisticated system for embedding external content in YaleSites. For technical details about the module architecture, plugin system, and field types, see the [ys_embed README](./README.md).

This guide focuses specifically on developing GitHub Applet applications that integrate with the module's GitHub Applet embed source.

## Table of Contents

1. [Project Setup & Requirements](#project-setup--requirements)
2. [Configuration Files](#configuration-files)
3. [Build System Setup](#build-system-setup)
4. [YaleSites Embed Integration](#yalesites-embed-integration)
5. [Deployment Process](#deployment-process)
6. [Development Standards](#development-standards)
7. [Testing & Validation](#testing--validation)
8. [Staying Current](#staying-current)
9. [Troubleshooting](#troubleshooting)

## Project Setup & Requirements

### Repository Naming Convention
- Repository name will become the mount point ID: `{repo-name}` → `#repo-name`
- Use kebab-case naming (e.g., `my-awesome-app`)
- Repository can be private - GitHub Pages sites can be public even from private repositories

### Required Dependencies
```bash
# Essential packages for GitHub Pages deployment
npm install --save-dev gh-pages vite @vitejs/plugin-react typescript

# For React applications
npm install react react-dom
npm install --save-dev @types/react @types/react-dom
```

### Project Structure
```
your-app-name/
|-- src/
|   |-- main.tsx          # Entry point - CRITICAL mount configuration
|   |-- App.tsx           # Main application component
|   +-- App.css           # Application styles
|-- package.json          # Build scripts and dependencies
|-- vite.config.ts        # Build configuration - CRITICAL for embed
|-- tsconfig.json         # TypeScript configuration
+-- README.md             # Documentation
```

## Configuration Files

### 1. package.json Configuration

**Critical Elements:**
```json
{
  "name": "your-app-name",
  "homepage": "https://yalesites-org.github.io/your-app-name",
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "lint": "eslint .",
    "preview": "vite preview",
    "predeploy": "npm run build",
    "deploy": "gh-pages -d dist"
  }
}
```

**Required Scripts:**
- `build`: Must compile TypeScript and build with Vite
- `predeploy`: Automatically builds before deployment
- `deploy`: Deploys `/dist` to `gh-pages` branch

### 2. vite.config.ts Configuration

**CRITICAL CONFIGURATION - Must be exact (syntax current as of Vite 6.0+):**
```typescript
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: '/your-app-name/',                    // MUST match repository name
  build: {
    rollupOptions: {
      output: {
        entryFileNames: 'assets/app.js',       // REQUIRED: Fixed filename in assets/
        assetFileNames: 'assets/app.css'       // REQUIRED: Fixed filename in assets/
      }
    }
  }
});
```

**Why This Configuration is Critical:**
- `base`: GitHub Pages serves from `/repo-name/` path
- `entryFileNames`: YaleSites embed expects exactly `assets/app.js`
- `assetFileNames`: YaleSites embed expects exactly `assets/app.css`
- **MANDATORY**: Assets MUST be in `assets/` subdirectory - YaleSites regex pattern will NOT find files in root directory

**Note**: While build tool syntax may change over time, these output requirements (exact filenames and directory structure) are fundamental to YaleSites integration.

### 3. main.tsx Entry Point

**CRITICAL MOUNT POINT CONFIGURATION:**
```typescript
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.tsx'

// CRITICAL: ID must exactly match repository name
createRoot(document.getElementById('your-app-name')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
```

**Mount Point Rules:**
- Element ID MUST exactly match repository name
- Use kebab-case (hyphens, not underscores or camelCase)
- YaleSites creates this div automatically during embed

**Note**: The specific React mounting syntax may evolve, but the requirement for the element ID to match the repository name is fundamental to the embed system.

## Build System Setup

### TypeScript Configuration
Create `tsconfig.json`:
```json
{
  "files": [],
  "references": [
    { "path": "./tsconfig.app.json" },
    { "path": "./tsconfig.node.json" }
  ]
}
```

### ESLint Configuration
Create `eslint.config.js` (ESLint 9+ flat config format):
```javascript
import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'

export default tseslint.config(
  { ignores: ['dist'] },
  {
    extends: [js.configs.recommended, ...tseslint.configs.recommended],
    files: ['**/*.{ts,tsx}'],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
    },
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': [
        'warn',
        { allowConstantExport: true },
      ],
    },
  },
)
```

**Note**: ESLint configuration formats evolve frequently. Check current ESLint documentation for the latest syntax. The goal is to have working linting for TypeScript and React.

## YaleSites Embed Integration

### How the Embed System Works

1. **URL Pattern**: `https://yalesites-org.github.io/{repo_name}/assets/` 
2. **Asset Loading**: System loads `app.js` and `app.css` from the `assets/` directory
3. **Container Creation**: Creates `<div id="{repo_name}"></div>`
4. **Application Mount**: Your React app mounts to this container

### Build Structure Explanation

When you run `npm run build`:
1. Vite builds your app to the `dist/` directory
2. The `dist/` directory contains `index.html` and an `assets/` subdirectory
3. `gh-pages` deploys the **contents** of `dist/` to GitHub Pages root
4. Result: `assets/app.js` and `assets/app.css` are available at the GitHub Pages URL

### Critical Assets Directory Requirement

**IMPORTANT**: The YaleSites embed system uses regex patterns to locate `app.js` and `app.css` files in the `assets/` subdirectory.

- **Correct**: `https://yalesites-org.github.io/your-app/assets/app.js`
- **Correct**: `https://yalesites-org.github.io/your-app/assets/app.css`
- **Incorrect**: `https://yalesites-org.github.io/your-app/app.js` (will not be found)

Files must be in the `assets/` subdirectory - files in the root directory will be ignored by the YaleSites embed system.

### Embed Requirements Checklist

- [ ] Repository is in `yalesites-org` organization (can be private with public GitHub Pages)
- [ ] Build outputs exactly `assets/app.js` and `assets/app.css`
- [ ] Main entry point mounts to element with ID matching repo name
- [ ] Base path configured for GitHub Pages (`/repo-name/`)
- [ ] Application handles cross-origin embedding (CORS considerations)

### CORS & Cross-Origin Considerations

**Important Notes:**
- Your app will be loaded in an iframe on YaleSites domains
- External API calls may be restricted by CORS policies
- Consider using proxy endpoints if needed for external data
- Test thoroughly in cross-origin context

### Repository Privacy Benefits
- **Source code remains private** while the built application is publicly accessible
- Only the compiled assets (`app.js`, `app.css`, `index.html`) are exposed via GitHub Pages
- Ideal for organizational projects where code should remain secure but applications need public access

## Deployment Process

### GitHub Pages Setup

1. **Enable GitHub Pages:**
   ```bash
   # Repository Settings → Pages → Source: Deploy from a branch
   # Branch: gh-pages (will be created automatically)
   # Note: GitHub Pages can be public even if repository is private
   ```

2. **Deploy Command:**
   ```bash
   npm run deploy
   ```

3. **Automated Workflow:**
   - `predeploy` runs automatically before `deploy`
   - Builds application to `/dist`
   - Pushes `/dist` contents to `gh-pages` branch
   - GitHub Pages serves from `gh-pages` branch

### Deployment Checklist

- [ ] GitHub Pages enabled on `gh-pages` branch (repository can be private)
- [ ] GitHub Pages site is publicly accessible
- [ ] Build command produces assets in correct location
- [ ] Assets are named exactly `app.js` and `app.css`
- [ ] Application mounts to correct element ID
- [ ] Base path matches repository name

## Development Standards

### Code Quality Requirements

**TypeScript:**
- All files must be TypeScript (.tsx, .ts)
- Strict type checking enabled
- No TypeScript errors in build

**Linting:**
```bash
npm run lint        # Must pass without errors
```

**Build Process:**
```bash
npm run build       # Must complete successfully
npm run preview     # Test production build locally
```

### Accessibility Standards

**WCAG 2.1 AA Compliance Required:**
- Semantic HTML structure
- Proper ARIA labels and roles
- Keyboard navigation support
- Screen reader compatibility
- Color contrast compliance

**Example Accessibility Implementation:**
```typescript
// Form fields linked to results
<input 
  aria-controls="search-results"
  aria-label="Search PO Boxes"
/>

// Live announcements for screen readers
<div role="alert" aria-live="polite">
  {`Showing ${filteredData.length} results`}
</div>

// Table sorting with ARIA
<th aria-sort={sortDirection}>
  Column Header
</th>
```

### Accessibility Testing Tools

**Automated Testing (current as of 2024):**
- **axe DevTools**: Browser extension for automated accessibility scanning
- **axe-core**: JavaScript library for programmatic testing
- **Lighthouse**: Built into Chrome DevTools, includes accessibility audit
- **WAVE**: Web Accessibility Evaluation Tool browser extension

**Manual Testing:**
- **Screen readers**: Test with NVDA (Windows), JAWS (Windows), VoiceOver (Mac)
- **Keyboard navigation**: Ensure all functionality works without mouse
- **Color contrast**: Use tools like WebAIM Contrast Checker

**Installation & Usage:**
```bash
# Install axe-core for automated testing
npm install --save-dev @axe-core/react

# Add to your test setup
import { axe, toHaveNoViolations } from 'jest-axe';
expect.extend(toHaveNoViolations);

# Example test
const results = await axe(container);
expect(results).toHaveNoViolations();
```

### Performance Standards

- First Contentful Paint < 2s
- Bundle size optimization
- Lazy loading for large datasets
- Efficient re-rendering patterns

## Testing & Validation

### Local Development Testing

```bash
# 1. Start development server
npm run dev

# 2. Test production build
npm run build
npm run preview

# 3. Accessibility testing
# Install axe DevTools browser extension
# Run Lighthouse accessibility audit in Chrome DevTools
# Test keyboard navigation (Tab, Enter, Escape, Arrow keys)
# Test with screen reader (VoiceOver on Mac, NVDA on Windows)
```

### Pre-Deployment Testing

```bash
# 1. Clean build test
rm -rf dist node_modules
npm install
npm run build

# 2. Lint check
npm run lint

# 3. TypeScript check
npx tsc --noEmit
```

### Post-Deployment Validation

1. **Verify GitHub Pages URL:**
   `https://yalesites-org.github.io/your-app-name/`

2. **Check Asset URLs:**
   - `https://yalesites-org.github.io/your-app-name/assets/app.js`
   - `https://yalesites-org.github.io/your-app-name/assets/app.css`

3. **Test Embed Integration:**
   - Verify YaleSites can load the application
   - Test in actual YaleSites environment
   - Confirm mount point works correctly

### Pre-Publishing Audit Checklist

**Before deploying to production, perform these critical audits:**

#### Security Audit
- [ ] **No sensitive data**: Verify no API keys, passwords, or secrets in source code
- [ ] **Dependencies scan**: Run `npm audit` and resolve high/critical vulnerabilities
- [ ] **CORS configuration**: Ensure external API calls are properly configured
- [ ] **Input validation**: Validate all user inputs and external data
- [ ] **Content Security Policy**: Consider CSP headers for enhanced security

#### Accessibility Audit
- [ ] **axe DevTools**: Run automated accessibility scan with zero violations
- [ ] **Lighthouse**: Achieve accessibility score of 95+ in Chrome DevTools
- [ ] **Keyboard navigation**: Test all functionality works without mouse
- [ ] **Screen reader**: Test with at least one screen reader (VoiceOver, NVDA)
- [ ] **Color contrast**: Verify WCAG 2.1 AA contrast requirements (4.5:1 minimum)
- [ ] **Focus indicators**: Ensure visible focus indicators for all interactive elements

#### Performance Audit
- [ ] **Bundle size**: Check production build size is reasonable (< 1MB recommended)
- [ ] **Load time**: First Contentful Paint under 2 seconds
- [ ] **Lighthouse performance**: Score of 90+ recommended
- [ ] **Network requests**: Minimize unnecessary API calls

#### Quality Assurance
- [ ] **Cross-browser testing**: Test in Chrome, Firefox, Safari, Edge
- [ ] **Mobile responsiveness**: Test on various screen sizes
- [ ] **Error handling**: Verify graceful handling of network failures and edge cases
- [ ] **Code quality**: All linting and TypeScript checks pass
- [ ] **Functionality**: Complete end-to-end testing of all features

#### YaleSites Integration
- [ ] **Embed testing**: Verify application works correctly when embedded in YaleSites
- [ ] **Cross-origin**: Test functionality in iframe context
- [ ] **Asset loading**: Confirm `assets/app.js` and `assets/app.css` load correctly
- [ ] **Mount point**: Verify application mounts to correct element ID

**Tools for Auditing:**
```bash
# Security
npm audit
npm audit fix

# Performance and accessibility
# Use Chrome DevTools Lighthouse
# Install axe DevTools browser extension

# Code quality
npm run lint
npm run build
npm run preview
```

## Staying Current

### Documentation Sources

**Always check current documentation for the latest syntax and best practices:**

- **Vite**: https://vitejs.dev/config/ - Build configuration reference
- **React**: https://react.dev/learn - Component and API documentation  
- **TypeScript**: https://www.typescriptlang.org/docs/ - Configuration and syntax
- **ESLint**: https://eslint.org/docs/latest/ - Linting configuration (note: flat config is the current standard)
- **GitHub Pages**: https://docs.github.com/en/pages - Deployment and setup
- **WCAG Guidelines**: https://www.w3.org/WAI/WCAG21/quickref/ - Accessibility standards

### When Tools Change

**If build configurations stop working:**

1. **Check tool documentation** for breaking changes in new versions
2. **Review migration guides** published by tool maintainers
3. **Test with latest versions** in a separate branch before updating main code
4. **Verify core requirements still met**: `assets/app.js`, `assets/app.css`, proper mount point

### Maintenance Checklist

**Periodically review (every 6-12 months):**
- [ ] Update dependency versions and test builds
- [ ] Check for new accessibility standards or tools
- [ ] Verify GitHub Pages and YaleSites embed system still work as expected
- [ ] Review this guide against current tool documentation
- [ ] Test applications with latest browsers

### Core Requirements That Rarely Change

**These fundamental requirements should remain stable:**
- Files must be named exactly `app.js` and `app.css`
- Files must be in a subdirectory (typically `assets/`)
- Mount point ID must match repository name exactly
- Repository must be accessible via GitHub Pages
- Application must handle cross-origin embedding

**When in doubt, prioritize these core requirements over specific build tool syntax.**

## Troubleshooting

### Common Issues & Solutions

**1. Application Not Loading in YaleSites**
```typescript
// Check main.tsx mount point
createRoot(document.getElementById('exact-repo-name')!).render(
```

**2. Assets Not Found (404 Errors)**
```typescript
// Check vite.config.ts output configuration
output: {
  entryFileNames: 'assets/app.js',    // Must be exactly assets/app.js
  assetFileNames: 'assets/app.css'    // Must be exactly assets/app.css
}
```

**Common mistake**: Putting files in root directory instead of assets/ subdirectory

**3. Routing Issues on GitHub Pages**
```typescript
// Check base path in vite.config.ts
base: '/your-repo-name/',  // Must match repository name exactly
```

**4. TypeScript Build Errors**
```bash
# Clear cache and rebuild
rm -rf dist node_modules
npm install
npm run build
```

**5. CORS Issues with External APIs**
- Use fetch with proper headers
- Consider proxy endpoints for external data
- Test in cross-origin context

### Debug Commands

```bash
# Verify build output structure
npm run build && ls -la dist/assets/

# Check GitHub Pages deployment
npm run deploy -- --verbose

# Test production build locally
npm run preview
```

## Working Example Structure

All the configurations shown in this guide are based on a working implementation that follows these exact patterns. The key elements you need are:

**Essential Configuration Files:**
- `vite.config.ts` - Build configuration with `assets/app.js` and `assets/app.css` output
- `src/main.tsx` - Mount point configuration matching repository name
- `package.json` - Homepage URL and deploy scripts

## Best Practices Summary

1. **Follow exact naming conventions** for all critical configuration
2. **Complete pre-publishing audit checklist** before deploying to production
3. **Test locally** with production build before deploying
4. **Validate security and accessibility** throughout development
5. **Keep bundle size minimal** for fast loading
6. **Handle errors gracefully** for better user experience
7. **Document any external dependencies** or API requirements
8. **Test cross-origin behavior** and YaleSites embedding before final deployment

---

*This guide ensures your application will integrate seamlessly with YaleSites using the GitHub Applet embed system. For technical details about the ys_embed module architecture, see the [module README](./README.md).*
