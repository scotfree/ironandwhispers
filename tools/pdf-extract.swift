import PDFKit
import AppKit
let a = CommandLine.arguments
let doc = PDFDocument(url: URL(fileURLWithPath: a[1]))!
for i in 0..<doc.pageCount {
  let p = doc.page(at: i)!
  print("=== PAGE \(i+1) ===")
  print(p.string ?? "")
  let b = p.bounds(for: .mediaBox)
  let w: CGFloat = 1100
  let img = p.thumbnail(of: NSSize(width: w, height: w * b.height / b.width), for: .mediaBox)
  let rep = NSBitmapImageRep(data: img.tiffRepresentation!)!
  let png = rep.representation(using: .png, properties: [:])!
  try! png.write(to: URL(fileURLWithPath: a[2] + "/p\(String(format: "%02d", i+1)).png"))
}
