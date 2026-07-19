import ddddocr
import sys
import os

def solve_captcha(image_path):
    if not os.path.exists(image_path):
        return "ERROR_FILE_NOT_FOUND"
    
    ocr = ddddocr.DdddOcr(show_ad=False)
    with open(image_path, 'rb') as f:
        img_bytes = f.read()
    
    res = ocr.classification(img_bytes)
    return res

if __name__ == '__main__':
    # Check mode
    if len(sys.argv) > 1 and sys.argv[1] == '--check':
        try:
            # Just try adding an instance to verify library works
            ocr = ddddocr.DdddOcr(show_ad=False)
            print("OK")
            sys.exit(0)
        except Exception as e:
            print(f"ERROR: {e}")
            sys.exit(1)

    if len(sys.argv) < 2:
        print("Usage: python ocr.py <image_path>")
        sys.exit(1)
    
    image_path = sys.argv[1]
    result = solve_captcha(image_path)
    print(result)
