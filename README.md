Extract OCR (plugin upgraded for Omeka S)
=============================


Summary
-----------

Omeka plugin to extract OCR text in XML from PDF files, allowing fulltext searching within Universal Viewer plugin for omeka S ( need [IIIF-Search module](https://github.com/bubdxm/Omeka-S-module-IiifSearch) ).

Installation
------------
- This plugin needs pdftohtml command-line tool on your server

```
    sudo apt-get install poppler-utils
```

- Upload the Extract OCR plugin folder into your plugins folder on the server;
- you can install the plugin via github

```
    cd omeka-s/modules  
    git clone git@github.com:bubdxm/Omeka-S-module-ExtractOcr.git "ExtractOcr"
```

- Install it from the admin → Modules → Extract Ocr -> install
- Extract OCR automaticaly allow the upload of XML files 

Using the Extract OCR Plugin
---------------------------

- Create an item
- Save this Item
- After save, add PDF file(s) to this item
- To locate extracted OCR xml file, select the item to which the PDF is attached. Normally, you should see an XML file attached to the record with the same filename than the pdf file. 


Optional plugins
----------------

- [Universal Viewer](https://github.com/Daniel-KM/Omeka-S-module-UniversalViewer) : Module for Omeka S that adds the IIIF specifications in order to act like an IIPImage server, and the UniversalViewer, a unified online player for any file. It can display books, images, maps, audio, movies, pdf, 3D views, and anything else as long as the appropriate extensions are installed.

- [IIIF-Server](https://github.com/bubdxm/Omeka-S-module-IiifServer) : Module for Omeka S that adds the IIIF specifications to serve any images and medias. 

- [IIIF-Search](https://github.com/bubdxm/Omeka-S-module-IiifSearch) :  Module for Omeka S that adds IIIF Search Api for  fulltext searching on universal viewer.


Troubleshooting
---------------

See online [Extract OCR issues](https://github.com/bubdxm/Omeka-S-module-ExtractOcr/issues).


License
-------

This plugin is published under [GNU/GPL].

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


Contact
-------

* Syvain Machefert, Université Bordeaux 3 (see [symac](https://github.com/symac))




