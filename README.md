Extract OCR (plugin upgraded for Omeka S)
=========================================


Module for Omeka S to extract OCR text in XML from PDF files, allowing fulltext
searching within Universal Viewer plugin for omeka S  need [IIIF-Search module](https://github.com/bubdxm/Omeka-S-module-IiifSearch)).


Installation
------------

- This module needs `pdftohtml` command-line tool on your server, from the
  poppler utilities:

```
    # Debian and derivatives
    sudo apt install poppler-utils
    # Red Hat and derivatives
    sudo dnf install poppler-utils
```

- Before S Omeka version 3.1, the module requires to set the base uri in the
  config file of Omeka `config/local.config.php` in order to upload the file in
  background:

```
    'file_store' => [
        'local' => [
            'base_path' => null, // Or the full path on the server if needed.
            'base_uri' => 'https://example.org/files', // To be removed in Omeka S v3.1.
        ],
    ],
```

- Upload and unzip the Extract OCR module folder into your modules folder on the
  server, or you can install the module via github:

```
    cd omeka-s/modules
    git clone git@github.com:bubdxm/Omeka-S-module-ExtractOcr.git "ExtractOcr"
```

- Take care to rename the folder "ExtractOcr".
- Install it from the admin → Modules → Extract Ocr -> install
- Extract OCR automaticaly allows the upload of XML files.

Using the Extract OCR module
---------------------------

- Create an item
- Save this Item
- After save, add PDF file(s) to this item
- To locate extracted OCR xml file, select the item to which the PDF is
  attached. Normally, you should see an XML file attached to the record with the
  same filename than the pdf file.


Optional modules
----------------

- [IIIF-Server](https://github.com/bubdxm/Omeka-S-module-IiifServer): Module for
  Omeka S that adds the IIIF specifications to serve any images and medias.
- [IIIF-Search](https://github.com/bubdxm/Omeka-S-module-IiifSearch):  Module
  for Omeka S that adds IIIF Search Api for fulltext searching on universal
  viewer.
- [Universal Viewer](https://gitlab.com/Daniel-KM/Omeka-S-module-UniversalViewer):
  Module for Omeka S that includes UniversalViewer, a unified online player for
  any file. It can display books, images, maps, audio, movies, pdf, 3D views,
  and anything else as long as the appropriate extensions are installed.
  Or any other IIIF viewers, like [Mirador](https://gitlab.com/Daniel-KM/Omeka-S-module-Mirador).


Troubleshooting
---------------

See online [Extract OCR issues](https://github.com/bubdxm/Omeka-S-module-ExtractOcr/issues).


License
-------

This module is published under [GNU/GPL](https://www.gnu.org/licenses/gpl-3.0.html).

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
details.

You should have received a copy of the GNU General Public License along with
this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.


Copyright
---------

* Copyright Syvain Machefert, Université Bordeaux 3 (see [symac](https://github.com/symac))
* Copyright Daniel Berthereau, 2020 (see [Daniel-KM](https://gitlab.com/Daniel-KM) on GitLab)
