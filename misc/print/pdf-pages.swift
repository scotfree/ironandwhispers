// Rasterise every page of a PDF to an exact pixel size, white background.
// swift pdf-pages.swift <in.pdf> <outdir> <width> <height>
// Writes <outdir>/rules-pNN.png. PDFKit, because pdftoppm is not installed.
import PDFKit
import AppKit

let a = CommandLine.arguments
let doc = PDFDocument(url: URL(fileURLWithPath: a[1]))!
let w = Int(a[3])!, h = Int(a[4])!
for i in 0..<doc.pageCount {
    let page = doc.page(at: i)!
    let box = page.bounds(for: .mediaBox)
    let rep = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: w, pixelsHigh: h,
                               bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true,
                               isPlanar: false, colorSpaceName: .deviceRGB,
                               bytesPerRow: 0, bitsPerPixel: 0)!
    let ctx = NSGraphicsContext(bitmapImageRep: rep)!
    let cg = ctx.cgContext
    cg.setFillColor(NSColor.white.cgColor)
    cg.fill(CGRect(x: 0, y: 0, width: w, height: h))
    cg.scaleBy(x: CGFloat(w) / box.width, y: CGFloat(h) / box.height)
    page.draw(with: .mediaBox, to: cg)
    let png = rep.representation(using: .png, properties: [:])!
    try! png.write(to: URL(fileURLWithPath: "\(a[2])/rules-p\(String(format: "%02d", i + 1)).png"))
}
