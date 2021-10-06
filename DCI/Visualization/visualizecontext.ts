enum Clicks {
    Single = 1,
    Double,
    Triple
}

type VisualizeContextState = {
    onlyInteractions: boolean
}

class VisualizeContext {
    constructor(nodes: vis.Node[], edges: vis.Edge[], container: HTMLElement, initialState?: VisualizeContextState) {
        //this.roles = new Set(nodes.map(node => node.group))

        const nodeSet = this.nodes = new vis.DataSet(nodes.map((node, index, arr) => {
            const angle = 2 * Math.PI * (index / arr.length + 0.75);
            const radius = 225 + arr.length * 10

            const output = Object.assign({}, node)
            output.x = radius * Math.cos(angle);
            output.y = radius * Math.sin(angle);
            return output;
        }))

        const edgeSet = this.edges = new vis.DataSet(
            edges.map(e => Object.assign({}, e))
        )

        // Set node border and size based on connected edges        
        nodeSet.update(nodeSet.get()
        .map(node => {
            const nodeEdgesFrom = edgeSet.get({filter: e => e.from == node.id})
            const nodeEdgesTo = edgeSet.get({filter: e => e.to == node.id})

            const uniqueEdges = (edges) => new Set(edges.map(e => e.from + e.to))
            const borderWidth = uniqueEdges(nodeEdgesTo).size * 1.5

            if(nodeEdgesFrom.length == 0)
                this._getterNodes.push(node.id)

            return {
                id: node.id,
                shape: node.group != '__CONTEXT' 
                    ? (nodeEdgesFrom.length > 0 ? 'dot' : 'diamond')
                    : null,
                borderWidth: borderWidth,
                borderWidthSelected: borderWidth,
                size: 20 + nodeEdgesFrom.length * 3
            }
        }))

        const networkOptions = {
            physics: false,
            nodes: {
                shape: 'dot',
                font: {
                    size: 16
                }
            },
            edges: {
                arrows: 'to',
                selectionWidth: width => Math.max(3, width * 1.5)
            },
            groups: {
                '__CONTEXT': {
                    shape: 'box',
                    shapeProperties: {
                        borderRadius: 1
                    },
                    color: '#bbb',
                    font: {
                        size: 18
                    }
                }
            }
        }

        this.network = new vis.Network(container, {
            nodes: nodeSet,
            edges: edgeSet
        }, networkOptions as any)

        this._state = Object.assign({
            onlyInteractions: false
        }, initialState || {})

        this.clicks = [0, 0]
        
    } // end constructor

    
    ///// System operations /////////////////////////////////////////
    
    start() {        
        const network = this.network as vis.Network
        network.on("click", () => {
            this.network_displaySelection(this.clicks_track())
        })
        this.redraw()
    }

    setState(state: VisualizeContextState) {
        this._state = state
        this.redraw()
    }

    redraw() {
        this.network_displaySelection(Clicks.Single)
    }

    ///// State /////////////////////////////////////////////////////
    
    private _getterNodes : vis.IdType[] = []

    private _state : {
        onlyInteractions: boolean
    }

    ///// Roles /////////////////////////////////////////////////////

    ///// edges ///////////////////////////////////////////

    private edges: {
        get() : vis.Edge[]
        get(id: vis.IdType, options?: vis.DataSelectionOptions<vis.Edge>): vis.Edge | null;
        get(ids: vis.IdType[], options?: vis.DataSelectionOptions<vis.Edge>): vis.Edge[]
        get(options?: vis.DataSelectionOptions<vis.Edge>): vis.Edge[]
        update(data: vis.Edge | vis.Edge[], senderId?: vis.IdType): vis.IdType[]
    }

    protected edges_displayAll() : void {
        this.edges_display(null)
    }

    protected edges_hideAll() : void {
        this.edges_display(null, false)
    }

    protected edges_display(edgeIds?: vis.IdType[], display = true) : void {
        if(edgeIds == null)
            edgeIds = this.edges.get().map(e => e.id)

        let updates = edgeIds.map(id => ({
            id: id,
            hidden: !display
        }))
        
        if(display && this._state.onlyInteractions) {
            // Only display edges for nodes that are selected
            // or isn't a getter node.
            const selection = this.network_selectedNodes()

            updates.forEach(u => {
                const edge = this.edges.get(u.id)

                if(!selection.includes(edge.to) &&
                    this._getterNodes.includes(edge.to)
                ) {
                    u.hidden = true
                }
            })
        }

        this.edges.update(updates)
    }

    ///// clicks //////////////////////////////////////////

    private clicks: [number, number];

    protected clicks_track() : Clicks {
        const now = Date.now()

        let nrClicks = Clicks.Single
        if(now - this.clicks[1] < 600) nrClicks = Clicks.Triple
        else if(now - this.clicks[0] < 500) nrClicks = Clicks.Double

        this.clicks.unshift(now)
        this.clicks.pop()

        return nrClicks
    }

    ///// network /////////////////////////////////////////

    private network: {
        getConnectedNodes(nodeOrEdgeId: vis.IdType, direction?: vis.DirectionType): vis.IdType[] | Array<{ fromId: vis.IdType, toId: vis.IdType }>;
        getConnectedEdges(nodeId: vis.IdType): vis.IdType[];
        getSelection(): { nodes: vis.IdType[], edges: vis.IdType[] };
    }

    protected network_selectedNodes() {
        return this.network.getSelection().nodes
    }

    protected network_displaySelection(modifier : Clicks) {
        const selected = this.network.getSelection()

        if(selected.nodes.length == 0 && selected.edges.length == 0) {
            this.edges_displayAll()
            return
        }

        // Hide all edges before displaying the selected ones
        this.edges_hideAll()

        const onlyExactNodes = modifier == Clicks.Single

        if(selected.nodes.length > 0 && modifier == Clicks.Triple) {
            this.edges_display(
                selected.nodes.flatMap(
                    nodeId => this.nodes_uniPathFrom(nodeId)
                )
            )
        } else if(selected.nodes.length > 0) {
            // Displaying nodes takes precedence above edges
            this.nodes_displayEdgesFor(selected.nodes, onlyExactNodes)
        } else {
            this.nodes_displayEdgesFor(
                selected.edges.flatMap(edgeId =>
                    this.network.getConnectedNodes(edgeId) as vis.IdType[]
                ),
                onlyExactNodes
            )
        }
    }

    protected network_connectedEdges(nodeId: vis.IdType) : vis.IdType[] {
        return this.network.getConnectedEdges(nodeId)
    }

    ///// nodes ///////////////////////////////////////////

    private nodes: {
        get(id: vis.IdType, options?: vis.DataSelectionOptions<vis.Node>): vis.Node | null;
        get(ids: vis.IdType[], options?: vis.DataSelectionOptions<vis.Node>): vis.Node[];
        get(options?: vis.DataSelectionOptions<vis.Node>): vis.Node[];
        //update(data: vis.Node | vis.Node[], senderId?: vis.IdType): vis.IdType[];
    }

    protected nodes_get(id: vis.IdType) : vis.Node {
        return this.nodes.get(id)
    }

    protected nodes_displayEdgesFor(nodeIdList: vis.IdType[], onlyExactNodes: boolean) : void {
        const nodes = this.nodes.get(nodeIdList)

        const filter = onlyExactNodes
            ? n => nodes.some(n2 => n2.id == n.id)
            : n => nodes.some(selected => selected.group == n.group)

        const edges = this.nodes
        .get({ filter: filter })
        .map(n => n.id)
        .flatMap(id => this.network_connectedEdges(id))

        this.edges_display(edges)
    }

    protected nodes_uniPathFrom(nodeId: vis.IdType, visitedIds: vis.IdType[] = []) : vis.IdType[] {
        visitedIds.push(nodeId)

        const fromEdges = (nodeId) => this.edges
        .get(this.network_connectedEdges(nodeId))
        .filter(e => e.from == nodeId)

        const allEdges = fromEdges(nodeId)

        const addEdges = allEdges
        .filter(e => fromEdges(e.to).length > 0)

        return addEdges.map(e => e.id).concat(
            allEdges
            .filter(e => !visitedIds.includes(e.to))
            .flatMap(e => this.nodes_uniPathFrom(e.to, visitedIds))
        )
    }
}
