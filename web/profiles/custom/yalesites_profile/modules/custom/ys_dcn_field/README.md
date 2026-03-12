# YaleSites DCN Field

Custom Drupal field type for Document Control Numbers (DCN) with taxonomy-based DCN types.

## Overview

This module provides a custom field type that combines:
- **DCN Type**: Entity reference to a taxonomy term (configurable vocabulary)
- **DCN Identifier**: Plain text field for the actual identifier value

Unlike the double_field implementation, this uses a real taxonomy vocabulary, making it easy to manage DCN types through the Drupal UI.

## Installation

1. Enable the module:
   ```bash
   drush en ys_dcn_field -y
   ```

2. Clear cache:
   ```bash
   drush cr
   ```

## What's Included

### Taxonomy Vocabulary
- **DCN Types** (`dcn_types`) vocabulary with default terms:
  - ISBN
  - Ref #
  - ISPS ID

You can manage these terms at: `/admin/structure/taxonomy/manage/dcn_types`

### Field Configuration
- **Field Name**: `field_cf_dcn`
- **Entity Type**: Node
- **Content Type**: Resource
- **Label**: "Document Control Number (Custom)"
- **Cardinality**: Unlimited (multiple values allowed)

## Usage

### For Content Editors

When editing a Resource node, you'll see the "Document Control Number (Custom)" field with:
1. A dropdown to select the DCN Type (ISBN, Ref #, ISPS ID, etc.)
2. A text field to enter the identifier (e.g., "978-0-306-40615-7")

Example output: **ISBN 978-0-306-40615-7**

### For Administrators

#### Adding New DCN Types
1. Go to `/admin/structure/taxonomy/manage/dcn_types/add`
2. Enter the term name (e.g., "DOI", "ISSN")
3. Save

The new type will immediately appear in the dropdown for all DCN fields.

#### Field Settings
Configure at: `/admin/structure/types/manage/resource/fields/node.resource.field_cf_dcn`

- **DCN Type Vocabulary**: Change which vocabulary to use for types
- **Required**: Make the field mandatory
- **Cardinality**: Allow multiple DCN values per resource

#### Display Settings
Configure at: `/admin/structure/types/manage/resource/display`

Format options:
- **Separator**: Text between type and identifier (default: space)
- **Show DCN type label**: Toggle whether to show the type name

## Comparison with field_dcn

| Feature | field_dcn (double_field) | field_cf_dcn (custom) |
|---------|-------------------------|----------------------|
| DCN Types | Hardcoded in config | Taxonomy vocabulary |
| Adding Types | Requires config export | Through UI |
| Type Management | Developer task | Content admin task |
| Field Type | double_field | Custom dcn_field |
| Storage | Two text columns | Entity reference + text |

## Technical Details

### Database Schema
```sql
dcn_type_target_id: int (references taxonomy_term_data.tid)
dcn_identifier: varchar(255)
```

### Plugins
- **FieldType**: `DcnFieldItem` (`dcn_field`)
- **FieldWidget**: `DcnFieldDefaultWidget` (`dcn_field_default`)
- **FieldFormatter**: `DcnFieldDefaultFormatter` (`dcn_field_default`)

### Files Structure
```
ys_dcn_field/
├── config/install/
│   ├── taxonomy.vocabulary.dcn_types.yml
│   ├── taxonomy.term.*.yml
│   ├── field.storage.node.field_cf_dcn.yml
│   └── field.field.node.resource.field_cf_dcn.yml
└── src/Plugin/Field/
    ├── FieldType/DcnFieldItem.php
    ├── FieldWidget/DcnFieldDefaultWidget.php
    └── FieldFormatter/DcnFieldDefaultFormatter.php
```

## Benefits

1. **Easier management**: Content admins can add/edit DCN types without developer involvement
2. **Consistency**: Uses standard Drupal taxonomy system
3. **Validation**: Ensures only valid terms are selected
4. **Reusable**: The DCN Types vocabulary can be referenced by other fields
5. **Query-friendly**: Easy to filter/search by DCN type using entity queries

## Future Enhancements

Possible additions:
- Link formatter (auto-link ISBNs to book databases, etc.)
- Validation rules per DCN type (e.g., ISBN format validation)
- Bulk import of DCN values
- Auto-complete widget for identifier field with suggestions
