class VisualizeContext {
    constructor(nodes, edges, container) {
        //this.roles = new Set(nodes.map(node => node.group))

        this.nodes = new vis.DataSet(nodes.map((node, index, arr) => {
            const angle = 2 * Math.PI * (index / arr.length + 0.75);
            const radius = 225 + arr.length * 10

            const output = Object.assign({}, node)
            output.x = radius * Math.cos(angle);
            output.y = radius * Math.sin(angle);
            return output;
        }))

        this.edges = new vis.DataSet(
            edges.map(e => Object.assign({}, e))
        )

        // Set node border and size based on connected edges        
        this.nodes.updateOnly(this.nodes.get()
        .map(node => {
            const nodeEdgesFrom = this.edges.get({filter: e => e.from == node.id})
            const nodeEdgesTo = this.edges.get({filter: e => e.to == node.id})
            const uniqueEdgesTo = (edges) => new Set(edges.map(e => e.from + e.to))

            return {
                id: node.id,
                borderWidth: uniqueEdgesTo(nodeEdgesTo).size * 1.5,
                borderWidthSelected: uniqueEdgesTo(nodeEdgesTo).size * 1.5,
                size: 20 + nodeEdgesFrom.length * 3
            }
        }))

        const options = {
            physics: false,
            nodes: {
                shape: 'dot',
                font: {
                    size: 16
                }
            },
            edges: {
                arrows: 'to'
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
            nodes: this.nodes,
            edges: this.edges
        }, options)

        // State
        this._lastClick = [0, 0]
        
    } // end constructor

    ///// System operations /////////////////////////////////////////

    start() {
        this.network.on("click", params => this.network_addToSelection(params))
    }

    ///// Roles /////////////////////////////////////////////////////

    ///// edges ///////////////////////////////////////////

    edges_display(edgeIds = null, display = true) {
        if(edgeIds === null) 
            edgeIds = this.edges.get().map(e => e.id)

        this.edges.updateOnly(
            edgeIds.map(id => ({
                id: id,
                hidden: !display
            }))
        )
    }

    ///// network /////////////////////////////////////////

    network_addToSelection(params) {
        // Check clicks
        const isDoubleClick = Date.now() - this._lastClick[0] < 500
        const isTripleClick = isDoubleClick && Date.now() - this._lastClick[1] < 600
        this._lastClick.unshift(Date.now())
        this._lastClick.pop()

        const nodeId = this.network.getNodeAt(params.pointer.DOM)
        const edgeId = this.network.getEdgeAt(params.pointer.DOM)

        if(!nodeId && !edgeId) {
            this.edges_display(null, true)
            return
        }

        // Hide all edges before displaying the selected ones
        this.edges_display(null, false)

        if(nodeId && isTripleClick) {
            this.edges_display(this.nodes_uniPathFrom(nodeId))
        } else {
            this.nodes_displayEdgesFor(
                nodeId ? [nodeId] : this.network.getConnectedNodes(edgeId),
                isDoubleClick
            )
        }        
    }

    network_connectedEdges(nodeId) {
        return this.network.getConnectedEdges(nodeId)
    }

    ///// nodes ///////////////////////////////////////////

    nodes_displayEdgesFor(nodeIdList, onlyExactNodes) {
        const nodes = this.nodes.get(nodeIdList)

        const filter = onlyExactNodes
            ? n => nodes.some(n2 => n2.id == n.id)
            : n => nodes.some(selected => selected.group == n.group)

        this.nodes__filterAndDisplayEdges(filter)
    }

    nodes__filterAndDisplayEdges(filter, visible = true) {
        const edges = this.nodes
        .get({ filter: filter || (n => true) })
        .map(n => n.id)
        .flatMap(id => this.network_connectedEdges(id))

        this.edges_display(edges, visible)
    }

    nodes_uniPathFrom(nodeId, visitedIds = []) {
        visitedIds.push(nodeId)

        const fromEdges = this.edges
        .get(this.network_connectedEdges(nodeId))
        .filter(e => e.from == nodeId)

        return fromEdges.map(e => e.id).concat(
            fromEdges
            .filter(e => !visitedIds.includes(e.to))
            .flatMap(e => this.nodes_uniPathFrom(e.to, visitedIds))
        )
    }
}
