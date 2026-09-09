+ Expression：柱形图可以切换成箱线图√
+ Dataset Status：换个新的，直观的表达方式
+ Sequence：修好结构SVG图，序列以repbase为准√
+ 整理文件夹，删除无用文件√
+ 将问答助手改造成一个智能体，具体有待商量
+ 将php减负，把其中的css、html等拆分出来，然后再引用√
+ 恢复JBrowse，且JBrowse可输入序列自主选择，而不是单选√
+ pmid可以显示影响因子等内容

  现在我希望点击跳转时，删除“第一段：点击瞬间，当成“选中节点”，先显示 SVA 简介 ””，并且展示加载动画，然后再显示第二段，并且确保不会被拉回画面中央。并且，当下方正在展示加载动画的时候，不再展示图例。只有当下方加载动画展示完毕后，显示“No node selectedClick a node or edge to inspect graph details.”再展示图例。