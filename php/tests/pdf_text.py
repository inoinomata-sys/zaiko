"""Extract text for PDF content checks using the bundled offline test dependency."""
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).parent / "vendor/pypdf-6.21.4-py3-none-any.whl"))
from pypdf import PdfReader

for page in PdfReader(sys.argv[1]).pages:
    print(page.extract_text())
