from dna_features_viewer import GraphicFeature, GraphicRecord
import matplotlib.pyplot as plt

LENGTH = 6064

features = [
    GraphicFeature(start=0, end=907, label="5′ UTR", color="#dddddd", 
                   label_class="utr5", box_class="utr5"),
    GraphicFeature(start=907, end=1921, label="ORF1", color="#b0e0e6",
                   label_class="orf1", box_class="orf1"),
    GraphicFeature(start=1987, end=2738, label="EN", color="#ffb3ba",
                   label_class="en", box_class="en"),
    GraphicFeature(start=2738, end=4988, label="RT", color="#baffc9",
                   label_class="rt", box_class="rt"),
    GraphicFeature(start=4988, end=5812, label="CTD", color="#f0f0f0",
                   label_class="ctd", box_class="ctd"),
    GraphicFeature(start=5812, end=6064, label="3′ UTR", color="#dddddd",
                   label_class="utr3", box_class="utr3"),
    GraphicFeature(start=6060, end=6064, label="polyA", color="#ffcc99",
                   label_class="polya", box_class="polya"),
]

record = GraphicRecord(sequence_length=LENGTH, features=features)
fig, ax = plt.subplots(figsize=(12, 1.5))
record.plot(ax=ax)

# 保存为 SVG
plt.savefig("L1HS_structure.svg", format="svg", bbox_inches="tight")
plt.close()