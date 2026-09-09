//
// Karotype - A SVG visualization of a genome karyotype with hit density overlay
//  
// Robert Hubley Nov 2018
//
//     Karyotype(parentElement, data)
//        - parentElement : any old HTML element.  How about a div?
//        - data : See datastructure details below
//
//     obj.switchVisualization(type)
//        - type: 'all', 'nrph', 'giesma'
//
// Basis Usage
// -----------
//    <script src='Karyotype.js'></script>
//    <script>
//        var karyotype_data = { .. };
//        var myKaryotype = new Karyotype( document.getElementById('karyotype'), 
//                                         karyotype_data );
//    </script>
//    <input type="button" value="All Hits" onclick="myKaryotype.switchVisualization('all');" />
//    <input type="button" value="NRPH Hits" onclick="myKaryotype.switchVisualization('nrph');" />
//    <input type="button" value="Giesma" onclick="myKaryotype.switchVisualization('giesma');" />
//
// Datastructure
// -------------
//    Contigs/Chroms are assumed to be in length sorted order ( largest first )
//
//         {
//            'singleton_contigs': [{      // A list of chrom/contigs that will be displayed
//                    'name': 'chr1',      // Name of chrom/contig
//                    'size': 283834921,   // Size (in BP) of chrom/contig
//                    'hit_clusters': [            // Precomputed clusters of all hits ( 1based )
//                        [3397261, 4529680, 23],  //    [ startPos, endPos, hitCount ]
//                        ...
//                    ],
//                    'nrph_hit_clusters': [    // Precomputed clusters of nrph filtered hits
//                        [3397261, 4529680, 23],
//                        ...
//                    ],
//                    'giesma_bands': [   // [optional] List of Giesma Staining Bands (see color chart)
//                        [50200000, 55600000, 3],  //   [ startPos, endPos, colorCode ]
//                        ...
//                    ]
//                },
//                {
//                    'name': 'chr2',
//                    'size': 200283321,
//                    'hit_clusters': [
//                    ],
//                    'nrph_hit_clusters': [
//                    ]
//                }
//            ],
//            'remaining_genome_contig': undefined,  // Placeholder for future use
//        };
//
//
// Giesma Staining Color Codes
// ---------------------------
//   0   acen    #527280
//   1   gneg    #ffffff
//   2   gvar    #c8c88c
//   3   gpos25  #e6e6e6 
//   4   gpos33  #c8c8c8
//   5   gpos50  #b4b4b4
//   6   gpos66  #8c8c8c
//   7   gpos75  #646464
//   8   gpos100 #323232
//   9   "n/a"   #ffffff
//   10  stalk   #823c5a
//
//
(function(global) {
    'use strict';

    function Karyotype(parent_element, karyotype_data) {
        this.karyotype_data = karyotype_data;

        // Glyph Size
        this.contigPixelWidth = 18;
        this.contigPixelSeparation = 14;
        this.contigCapPixelSize = 10;
        this.contigLabelDefaultSize = 15;

        // Legend Size
        this.legendWidth = 160;
        this.legendHeight = 200;
        this.legendRangeSize = null;  // The size of the legend ranges ( except for the last range ).  Depends
                                      // on the max counts in the dataset.

        // Giesma Karyotype Staining Colors
        this.giesmaColors = ["#527280", "#ffffff", "#c8c88c", "#e6e6e6", "#c8c8c8", "#b4b4b4", "#8c8c8c", "#646464", "#323232", "#ffffff", "#823c5a"];

        // Hit Count Legend Colors
        this.legendColors = [{
                'color': '#fff',
                'desc': '0'
            },
            {
                'color': '#3288bd',
            },
            {
                'color': '#66c2a5',
            },
            {
                'color': '#abdda4',
            },
            {
                'color': '#e6f598',
            },
            {
                'color': '#fee08b',
            },
            {
                'color': '#fdae61',
            },
            {
                'color': '#f46d43',
            },
            {
                'color': '#d53e4f',
            }
        ];
 
        // Get stats from datastructure
        this.contigLabelSize = this.contigLabelDefaultSize;
        this.maxHitClusterSize = 0;
        this.maxNrphHitClusterSize = 0;
        this.hasGiesmaDetails = 0;
        this.hasInferredLabels = 1;
        var i;
        for (i = 0; i < this.karyotype_data.singleton_contigs.length; i++) {
            var j;
            var contig = this.karyotype_data.singleton_contigs[i];
            // Log that at least on contig has Giesma band details
            if (contig.giesma_bands != null &&
                contig.giesma_bands.length > 0)
                this.hasGiesmaDetails = 1;
            // Get max cluster size for both datasets
            for (j = 0; j < contig.hit_clusters.length; j++) {
                if (contig.hit_clusters[j][2] > this.maxHitClusterSize)
                    this.maxHitClusterSize = contig.hit_clusters[j][2];
            }
            for (j = 0; j < contig.nrph_hit_clusters.length; j++) {
                if (contig.nrph_hit_clusters[j][2] > this.maxNrphHitClusterSize)
                    this.maxNrphHitClusterSize = contig.nrph_hit_clusters[j][2];
            }
            // Determine if we can infer sensible labels from the contig names
            var sensibleLabel = contig.name;
            if ( sensibleLabel != null )
              if ( contig.name.toLowerCase().startsWith("chromosome") )
                  sensibleLabel = sensibleLabel.replace(/^chromosome/i, '');
              else if ( contig.name.toLowerCase().startsWith("chr") )
                  sensibleLabel = sensibleLabel.replace(/^chr/i, '');
              if ( sensibleLabel.length > 3 )
              {
                  sensibleLabel = null;
                  this.hasInferredLabels = 0;
                  this.contigLabelSize = 0;
              }else {
                contig.sensibleLabel = sensibleLabel;
              }
        }

        // TODO: Assume nothing.  Sort first.
        this.largestContigBP = this.karyotype_data.singleton_contigs[0].size;

        // Place to hold some key elements
        this.container = parent_element;
        this.tooltipGroup = null;
        this.tooltipPath = null;
        this.tooltipText = null;


        // How many contig glyphs to display 
        //   -- currently setup for showing the genome remaining which
        //      isn't implemented.
        var numBars = this.karyotype_data.singleton_contigs.length;
        if (this.karyotype_data.remaining_genome_contig != null)
            numBars = numBars + 1;

        // Now the SVG width and pixelsPerBP can be determined
        this.svgHeight = 300;
        this.svgWidth = Math.floor((this.contigPixelWidth + this.contigPixelWidth) *
            numBars + this.legendWidth);

        this.currentVisualType = null;
        this.switchVisualization("all");
    }


    // Switch between visualizations
    Karyotype.prototype.switchVisualization = function(type) {
        var kObj = this;
        if (type != kObj.currentVisualType) {
            if (kObj.svg != null) {
                kObj.svg.parentNode.removeChild(kObj.svg);
            }
            kObj.svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            kObj.svg.setAttribute('width', this.svgWidth);
            kObj.svg.setAttribute('height', this.svgHeight);
            var maxCount = 0;
            if (type == "all") {
                maxCount = kObj.maxHitClusterSize;
                this.currentVisualType = type;
            } else if (type == "nrph") {
                maxCount = kObj.maxNrphHitClusterSize;
                this.currentVisualType = type;
            } else if (type == "giesma") {
                if (kObj.hasGiesmaDetails) {
                    this.currentVisualType = type;
                } else {
                    // Do not toggle to Giesma view if there is nothing to view!
                    maxCount = kObj.maxHitClusterSize;
                    this.currentVisualType = "all";
                }
            }
            // set legend ranges
            kObj.legendRangeSize = Math.ceil(maxCount / (kObj.legendColors.length - 1));
            var i;
            var rStart = 1;
            for (i = 1; i < kObj.legendColors.length - 1; i++) {
                kObj.legendColors[i].desc = rStart + "-" + (rStart + kObj.legendRangeSize - 1);
                rStart += kObj.legendRangeSize;
            }
            kObj.legendColors[kObj.legendColors.length - 1].desc = rStart + "-" + maxCount;

            kObj.pixelsPerBP = (this.svgHeight - (2 * this.contigCapPixelSize) - 
                                   this.contigLabelSize) / this.largestContigBP;
            kObj.visualizeKaryotype();
        }
    }

    // 
    //  Generate tooltip glyph based on a set of size parameters:
    //
    //      |----------W-----------|
    //      
    //       6____________________7      -  -
    //      /                      \     |  |
    //      5                      8     |  |
    //      |                      |     H  |
    //      4                      9     |  FH
    //      \                      /     |  |
    //       3--2  11------------10      _  |
    //          | /              |--|       |
    //           1                R         _
    //     |----|---|          
    //       off  S
    //
    //  C = control point, C<=R
    //
    Karyotype.prototype.getTooltipGlyphPath = function(FH, H, W, R, C, S, off) {
        var pathStr = "M " + (R + off) + "," + FH + " " + // Move to
            "V " + H + " " + // 1: Vertical line to
            "L " + R + "," + H + " " + // 2: Line to
            "Q " + (R - C) + "," + (H - (R - C)) + "," + 0 + "," + (H - R) + " " + // 3: Bezier
            "V " + R + " " + // 4: Vertical line to
            "Q " + (R - C) + "," + (R - C) + "," + R + "," + 0 + " " + // 5: Bezier
            "H " + (W - R) + " " + // 6: Horizontal line to
            "Q " + (W - (R - C)) + "," + 0 + "," + W + "," + R + " " + // 7: Bezier
            "V " + (H - R) + " " + // 8: Vertical line to
            "Q " + (W - (R - C)) + "," + (H - (R - C)) + "," + (W - R) + "," + H + " " + // 9: Bezier
            "H " + (R + off + S) + " " + // 10: Horizontal line to
            "L " + (R + off) + "," + FH + " z"; // 11: Line to and close
        return pathStr
    }

    // 
    // SVG Generation Central
    //   - Called by switchVisualization()
    //
    Karyotype.prototype.visualizeKaryotype = function() {
        var kObj = this;
        var svgNS = kObj.svg.namespaceURI;

        var i = 0;
        var startX = 0;
        // Draw singleton contigs
        for (i = 0; i < kObj.karyotype_data.singleton_contigs.length; i++) {
            var contig = kObj.karyotype_data.singleton_contigs[i];
            var contigPixelHeight = Math.floor(contig.size * kObj.pixelsPerBP);


            var cylinderY1 = kObj.svgHeight - kObj.contigLabelSize - kObj.contigCapPixelSize - contigPixelHeight;
            var cylinderY2 = kObj.svgHeight - kObj.contigLabelSize - kObj.contigCapPixelSize;

            var lLine = document.createElementNS(svgNS, 'line');
            lLine.setAttribute('x1', startX);
            lLine.setAttribute('x2', startX);
            lLine.setAttribute('y1', cylinderY1);
            lLine.setAttribute('y2', cylinderY2);
            lLine.setAttribute('stroke', '#95B3D7');
            kObj.svg.appendChild(lLine);

            var rLine = document.createElementNS(svgNS, 'line');
            rLine.setAttribute('x1', startX + kObj.contigPixelWidth);
            rLine.setAttribute('x2', startX + kObj.contigPixelWidth);
            rLine.setAttribute('y1', cylinderY1);
            rLine.setAttribute('y2', cylinderY2);
            rLine.setAttribute('stroke', '#95B3D7');
            kObj.svg.appendChild(rLine);

            // Draw contig caps
            var topCap = document.createElementNS(svgNS, 'path');
            topCap.setAttribute('d', "M " + startX + "," + cylinderY1 + " " +
                "Q " + Math.floor(startX + (kObj.contigPixelWidth / 2)) + "," +
                (cylinderY1 - 12) + "," +
                (startX + kObj.contigPixelWidth) + "," + cylinderY1);
            topCap.setAttribute('style', "fill: white; stroke: #95B3D7;");
            kObj.svg.appendChild(topCap);

            var botCap = document.createElementNS(svgNS, 'path');
            botCap.setAttribute('d', "M " + startX + "," + cylinderY2 + " " +
                "Q " + Math.floor(startX + (kObj.contigPixelWidth / 2)) + "," +
                (cylinderY2 + 12) + "," +
                (startX + kObj.contigPixelWidth) + "," + cylinderY2);
            botCap.setAttribute('style', "fill: white; stroke: #95B3D7;");
            kObj.svg.appendChild(botCap);

            // Draw sensible labels if they were all inferred adequately
            if ( kObj.hasInferredLabels )
            {
              var contigLabel = document.createElementNS(svgNS, 'text');
              contigLabel.appendChild(document.createTextNode(contig.sensibleLabel));
              //contigLabel.setAttribute('x', startX + Math.floor(( kObj.contigPixelWidth - textLen ) / 2));
              contigLabel.setAttribute('x', startX + Math.floor(kObj.contigPixelWidth / 2) );
              contigLabel.setAttribute('y', kObj.svgHeight - 10 );
              contigLabel.setAttribute('dominant-baseline', 'middle' );
              contigLabel.setAttribute('text-anchor', 'middle' );
              contigLabel.setAttribute('style', 'font-size: 10px;' );
              kObj.svg.appendChild(contigLabel);
            }

            // Draw hit clusters
            var j = 0;
            var clusters = contig.hit_clusters;
            if (kObj.currentVisualType == "nrph")
                clusters = contig.nrph_hit_clusters;
            for (j = 0; j < clusters.length; j++) {
                var start = clusters[j][0];
                var end = clusters[j][1];
                var count = clusters[j][2];
                var startY = Math.floor(start * kObj.pixelsPerBP);
                var endY = Math.floor(end * kObj.pixelsPerBP);

                // Hit Cluster SVG rectangle
                var hcBlock = document.createElementNS(svgNS, 'rect');
                hcBlock.setAttribute('x', startX + 1);
                hcBlock.setAttribute('y', cylinderY1 + startY);
                hcBlock.setAttribute('width', kObj.contigPixelWidth - 2);
                hcBlock.setAttribute('height', endY - startY);
                var colorIdx = Math.ceil(count / kObj.legendRangeSize);
                var color = "white";
                if (kObj.currentVisualType != "giesma" && colorIdx < kObj.legendColors.length)
                    color = kObj.legendColors[colorIdx].color;
                hcBlock.setAttribute('fill', color);
                hcBlock.setAttribute('data-tooltip-text', contig.name + ":" +
                    start + "-" +
                    end +
                    " count:" + count);

                hcBlock.addEventListener('mousemove', function(evt) {
                    var hcBlockEle = evt.target;
                    var CTM = kObj.svg.getScreenCTM();
                    var text = hcBlockEle.getAttributeNS(null, "data-tooltip-text");
                    var x = (evt.clientX - CTM.e - 8) / CTM.a;
                    var y = (evt.clientY - CTM.f - 34) / CTM.d;
                    var textEle = kObj.tooltipGroup.getElementsByTagName('text')[0];
                    textEle.firstChild.data = text;
                    kObj.tooltipGroup.setAttributeNS(null, "transform", "translate(" + x + " " + y + ")");
                    var textLen = kObj.tooltipText.getComputedTextLength();
                    var R = 5;
                    var H = 25;
                    var W = textLen + 20;
                    var FH = 30;
                    var off = 5;
                    var C = 5;
                    var S = 5;
                    kObj.tooltipPath.setAttribute('d',
                        kObj.getTooltipGlyphPath(FH, H, W, R, C, S, off));
                    kObj.tooltipGroup.setAttribute("style", "opacity: 100;");
                });


                hcBlock.addEventListener('mouseout', function() {
                    kObj.tooltipGroup.setAttribute("style", "opacity: 0;");
                    kObj.tooltipGroup.setAttributeNS(null, "transform", "translate(-100 -100)");
                });

                (function(contig_name, start, end) {
                  hcBlock.addEventListener('mousedown', function(evt) {
                      var event = new CustomEvent("karyotypeclicked", { detail: {
                        contig: contig_name,
                        start: start,
                        end: end,
                      }, bubbles: true });
                      hcBlock.dispatchEvent(event);
                  });
                })(contig.name, start, end);

                kObj.svg.appendChild(hcBlock);

            }

            if (kObj.currentVisualType == "giesma" && contig.giesma_bands != null) {
                var bands = contig.giesma_bands;
                for (j = 0; j < bands.length; j++) {
                    var startY = Math.floor(bands[j][0] * kObj.pixelsPerBP);
                    var endY = Math.floor(bands[j][1] * kObj.pixelsPerBP);

                    // Draw Giesma bands
                    var giBand = document.createElementNS(svgNS, 'rect');
                    giBand.setAttribute('x', startX);
                    giBand.setAttribute('y', (kObj.svgHeight - contigPixelHeight) + startY);
                    giBand.setAttribute('width', kObj.contigPixelWidth);
                    giBand.setAttribute('height', endY - startY);
                    var color = "white";
                    if (bands[j][2] < kObj.giesmaColors.length)
                        color = kObj.giesmaColors[bands[j][2]].color;
                    giBand.setAttribute('fill', color);
                    kObj.svg.appendChild(giBand);
                }
            }

            startX = startX + kObj.contigPixelWidth + kObj.contigPixelSeparation;
        }

        if (kObj.currentVisualType != "giesma") {
            // Draw legend

            //
            // First define a mask path for the frame
            //    This hides the top of the frame where the text will go
            var maskPath = document.createElementNS(svgNS, 'mask');
            maskPath.setAttributeNS(null, 'id', 'legendmask');
            var defMaskRect = document.createElementNS(svgNS, 'rect');
            defMaskRect.setAttribute("x", startX);
            defMaskRect.setAttribute("y", kObj.svgHeight - kObj.legendHeight);
            defMaskRect.setAttribute("width", kObj.legendWidth);
            defMaskRect.setAttribute("height", kObj.legendHeight);
            defMaskRect.setAttribute("style", "fill: white;");
            maskPath.appendChild(defMaskRect);
            var maskRect = document.createElementNS(svgNS, 'rect');
            maskRect.setAttribute("x", startX + 10);
            maskRect.setAttribute("y", kObj.svgHeight - kObj.legendHeight);
            maskRect.setAttribute("width", kObj.legendWidth - 25);
            maskRect.setAttribute("height", 20);
            maskRect.setAttribute("style", "fill: black;");
            maskPath.appendChild(maskRect);
            kObj.svg.appendChild(maskPath);

            //Legend Frame ( using mask )
            var legendFrame = document.createElementNS(svgNS, 'rect');
            legendFrame.setAttribute("x", startX);
            legendFrame.setAttribute("y", kObj.svgHeight - kObj.legendHeight);
            legendFrame.setAttribute("width", kObj.legendWidth);
            legendFrame.setAttribute("height", kObj.legendHeight);
            legendFrame.setAttribute("rx", 8);
            legendFrame.setAttribute("ry", 8);
            legendFrame.setAttribute("style", "fill: white; stroke: #ddd;");
            legendFrame.setAttribute("mask", 'url(#legendmask)');
            kObj.svg.appendChild(legendFrame);

            // Legend Title
            var legendTitle = document.createElementNS(svgNS, 'text');
            legendTitle.setAttribute('x', startX + 14);
            legendTitle.setAttribute('y', kObj.svgHeight - kObj.legendHeight + 6);
            // #404040;
            // Verdana,Arial,Helvetica,sans-serif;
            legendTitle.appendChild(document.createTextNode("Hit Count (per Mb)"));
            kObj.svg.appendChild(legendTitle);

            // Legend color boxes
            var colorBoxSize = 18;
            var startY = kObj.svgHeight - kObj.legendHeight + 16;
            for (i = 0; i < kObj.legendColors.length; i++) {
                var colorRect = document.createElementNS(svgNS, 'rect');
                colorRect.setAttribute("x", startX + 20);
                colorRect.setAttribute("y", startY);
                colorRect.setAttribute("width", colorBoxSize);
                colorRect.setAttribute("height", colorBoxSize);
                colorRect.setAttribute("style", "fill: " + kObj.legendColors[i].color + "; stroke: #ddd;");
                kObj.svg.appendChild(colorRect);
                var colorDesc = document.createElementNS(svgNS, 'text');
                colorDesc.setAttribute('x', startX + 20 + colorBoxSize);
                colorDesc.setAttribute('y', startY + colorBoxSize - 2);
                colorDesc.appendChild(document.createTextNode(": " + kObj.legendColors[i].desc));
                kObj.svg.appendChild(colorDesc);
                startY = startY + colorBoxSize + 2;
            }
        }

        // Draw the tooltip glyph
        kObj.tooltipGroup = document.createElementNS(svgNS, 'g');
        kObj.tooltipGroup.setAttribute('style', "opacity: 0;");
        kObj.tooltipPath = document.createElementNS(svgNS, 'path');
        kObj.tooltipPath.setAttribute('style', "fill: white; stroke: black;");
        kObj.tooltipGroup.appendChild(kObj.tooltipPath);
        var text = document.createElementNS(svgNS, 'text');
        kObj.tooltipText = text;
        text.setAttribute('x', 8);
        text.setAttribute('y', 18);
        var textstr = document.createTextNode("");
        text.appendChild(textstr);
        kObj.tooltipGroup.appendChild(text);
        kObj.svg.appendChild(kObj.tooltipGroup);

        kObj.container.appendChild(kObj.svg);
    }

    if (typeof module === 'object' && module && typeof module.exports === 'object') {
        // Expose functions/objects for loaders that implement the Node module pattern.
        module.exports = Karyotype;
    } else {
        // Otherwise expose ourselves directly to the global object.
        global.Karyotype = Karyotype;
        // Register as a named AMD module.
        if (typeof define === 'function' && define.amd) {
            define('karyotype', [], function() {
                return Karyotype;
            });
        }
    }
}(this.window || (typeof global != 'undefined' && global) || this));
