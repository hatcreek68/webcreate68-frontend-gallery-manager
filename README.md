# webcreate68 Frontend Gallery Manager

A Wordpress plugin for frontend photo gallery management. Includes drag-and-drop uploads, lightbox viewing, password protection, and automatic ZIP generation for whole album downloading. 

See usage below.  The intention is for the upload shortcode to be used on editor access controlled page, while the client gallery shortcode is used for a public page.

## Features

### Upload & Management
- **Drag & drop interface** using Dropzone.js
- **JPG-only uploads** (server + client-side validation)
- **Chunked uploads** for large files (up to 50MB per file)
- **Automatic image resizing** to 3480x2160px max (maintains aspect ratio)
- **Auto-ZIP generation** of original images
- **Gallery CRUD operations** (Create, Read, Update, Delete)
- **Password protection** with secure bcrypt hashing

### Display & Viewing
- **Lightbox modal** with keyboard navigation (←/→/Esc)
- **Lazy-loaded thumbnails** for fast page loads
- **Public thumbnail caching** (200x200px, 80% quality)
- **Password-protected full images** (session-based)
- **Thumbnail strip** with active image highlighting
- **ZIP download** with password verification
- **Chunked ZIP delivery** for large files (memory-safe)

### Security
- ✅ Nonce verification on all AJAX endpoints
- ✅ Capability checks (`edit_pages` required for management)
- ✅ File type validation (MIME + extension checking)
- ✅ Path traversal protection (`basename()` sanitization)
- ✅ Password hashing (PHP `password_hash()`)
- ✅ Session-based access control for protected images
- ✅ XSS prevention (HTML escaping)

---

## 📁 File Structure

```
webcreate68-frontend-gallery-manager/
├── webcreate68-frontend-gallery-manager.php  # Main plugin file
├── includes/
│   ├── upload-management.php                 # Upload, ZIP, CRUD operations
│   └── gallery-display.php                   # Public display & lightbox
├── assets/
│   ├── dropzone.min.css                      # Dropzone styles
│   ├── dropzone.min.js                       # Dropzone library
│   └── gallery-manager.js                    # Upload UI logic
└── README.md                                 # This file
```

---

## 🚀 Installation

1. **Upload the plugin folder** to `/wp-content/plugins/`
2. **Activate** via WordPress admin → Plugins
3. **Ensure Dropzone assets exist** in `/assets/` folder:
   - `dropzone.min.css`
   - `dropzone.min.js`

---

## 📝 Usage

### For Editors (Upload & Manage)

**Add this shortcode to any page**
**Intended for an editor-restricted page, allowing an editor account to effortlessly create and manage galleries.**
```
[manage_galleries_upload]
```

**Workflow:**
1. Drag & drop JPG files (or click to browse)
2. Enter gallery name when prompted (e.g., "Wedding | Smith 2025")
3. Set optional password
4. Files upload with progress bar
5. Gallery auto-creates ZIP and resizes images
6. Manage existing galleries: edit name/password or delete

**Requirements:**
- User must have `edit_pages` capability (Editor role or higher)

---

### For Public Display

**Add this shortcode to any page:**
```
[client_galleries]
```

**Features:**
- Displays all galleries as thumbnails
- Click thumbnail → password prompt → lightbox opens
- Navigate with arrows or keyboard (←/→)
- Download ZIP button (requires password)

---

## ⚙️ Technical Details

### Upload Settings
- **Max file size:** 50MB per file
- **Allowed formats:** JPG/JPEG only
- **Parallel uploads:** 5 concurrent
- **Storage location:** `/wp-content/uploads/front-end-managed-galleries/`

### Image Processing
- **Automatic resize:** Max 3480x2160px (landscape/portrait aware)
- **JPEG quality:** 85%
- **Thumbnail cache:** 200x200px @ 80% quality
- **Cache location:** `/wp-content/uploads/front-end-gallery-cache/`

### Password Protection
- **Hashing:** PHP `password_hash()` with `PASSWORD_DEFAULT` (bcrypt)
- **Session storage:** Verified galleries stored in `$_SESSION['wc68_verified']`
- **Scope:** Password applies to both lightbox viewing and ZIP downloads

---

## 🔧 Developer Notes

### Helper Functions

```php
wc68_galleries_base_path()  // Returns: /uploads/front-end-managed-galleries/
wc68_galleries_base_url()   // Returns: http://site.com/uploads/front-end-managed-galleries/
wc68_galleries_cache_path() // Returns: /uploads/front-end-gallery-cache/
```

### AJAX Endpoints

**Upload Management:**
- `webcreate68_upload_images` - Handle file upload
- `webcreate68_create_zip` - Generate ZIP + resize images
- `webcreate68_get_list` - Fetch gallery list
- `webcreate68_save_meta` - Update gallery name/password
- `webcreate68_delete_gallery` - Delete entire gallery

**Public Display:**
- `wc68_get_thumbnail` - Serve cached 200x200 thumbnails (public)
- `wc68_check_password` - Verify gallery password
- `wc68_get_image` - Serve full-size image (session-protected)
- `wc68_get_zip` - Stream ZIP file (session-protected, chunked)

### Metadata Format

**File:** `/.meta.json` (stored in each gallery folder)

```json
{
  "display_name": "Wedding | Smith 2025",
  "password_hash": "$2y$10$...",
  "created": "2025-01-15 14:30:00"
}
```

---

## 🐛 Troubleshooting

### Uploads fail silently
- Check PHP `upload_max_filesize` and `post_max_size` (must be ≥50MB)
- Verify folder permissions: `/wp-content/uploads/` must be writable

### Images don't resize
- Ensure PHP GD library is installed
- Check `memory_limit` (512M recommended for large images)

### ZIP downloads timeout
- Increase `max_execution_time` in php.ini (600+ recommended)
- Plugin uses chunked reading to minimize memory usage

### Session issues (password not persisting)
- Verify `session_start()` is called before headers sent
- Check for output before `wp_head` in theme

---

## 📋 Requirements

- **WordPress:** 5.0+
- **PHP:** 7.4+
- **PHP Extensions:** GD (for image processing), ZipArchive (for ZIP creation)
- **User Role:** Editor or higher for upload management

---

## 📄 License

This plugin is provided as-is for internal use. Modify as needed.

---

## 👤 Author

Author: P. Pace, Gemini, Claude
Version: 3.0  
Last Updated: 2025

---

## 🔄 Changelog

### Version 3.0 (2025-01-15)
- **MERGED:** Combined upload management + display into single plugin
- Added separate `display_name` preservation (fixes "gallery | 2025" → "gallery-2025" issue)
- Improved error handling for image resize operations
- Added file size validation (50MB max)
- Enhanced XSS protection in JavaScript
- Moved to modular structure (main + includes/)

### Version 2.9 (Previous)
- Added chunked upload progress tracking
- Improved JPG validation (client + server)
- Enhanced keyboard navigation in lightbox

### Version 2.3 (Previous)
- Added lazy loading for thumbnails
- Implemented thumbnail caching system
- Fixed session initialization issues
