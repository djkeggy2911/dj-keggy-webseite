# Local QR encoder

`lib/qr.php` is vendored unchanged from Kazuhiko Arase's qrcode-generator:
https://github.com/kazuhikoarase/qrcode-generator/blob/95af9c2e1249337047ca478ccf98650a0a30af13/php/qrcode.php

Commit: `95af9c2e1249337047ca478ccf98650a0a30af13`

SHA-256 with LF line endings:
`839337a00e1ab8be1916ab20702d724aa41cdd7991dd7fc8187ffbc602998b4e`

The encoder executes locally in PHP; no QR service, CDN or runtime network request
is used. PHP produces the matrix; our wrapper emits a monochrome SVG with a
four-module quiet zone. The test suite decodes it independently using ZXing.
Pillow and zxing-cpp are test dependencies only, not deployed site dependencies.

## MIT License

Copyright (c) 2009 Kazuhiko Arase

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
