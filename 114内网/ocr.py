import os
import sys

import ddddocr


def solve_captcha(image_path: str) -> str:
    if not os.path.exists(image_path):
        return "ERROR_FILE_NOT_FOUND"

    ocr = ddddocr.DdddOcr(show_ad=False)
    with open(image_path, "rb") as handle:
        image_bytes = handle.read()

    return ocr.classification(image_bytes)


def main() -> int:
    if len(sys.argv) > 1 and sys.argv[1] == "--check":
        try:
            ddddocr.DdddOcr(show_ad=False)
            print("OK")
            return 0
        except Exception as exc:
            print(f"ERROR: {exc}")
            return 1

    if len(sys.argv) < 2:
        print("ERROR: missing image path")
        return 1

    result = solve_captcha(sys.argv[1])
    print(result)
    return 0 if not result.startswith("ERROR") else 1


if __name__ == "__main__":
    raise SystemExit(main())
